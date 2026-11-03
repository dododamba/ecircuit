<?php

namespace App\Service;

use App\Entity\AdministrativeCircuit;
use App\Entity\CircuitStep;
use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use App\Entity\WorkflowTransition;
use App\Enum\WorkflowAction;
use Doctrine\ORM\EntityManagerInterface;

final class CircuitWorkflowSyncService
{
    private const CODE_MAPPING = [
        'CIRCUIT_CABINET_STANDARD' => 'WORKFLOW_CABINET_STANDARD',
        'CIRCUIT_SG_STANDARD' => 'WORKFLOW_SG_STANDARD',
        'CIRCUIT_PROVINCIAL' => 'WORKFLOW_PROVINCIAL',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    public function resolveWorkflowCode(AdministrativeCircuit $circuit): string
    {
        $code = strtoupper(trim($circuit->getCode()));
        if (isset(self::CODE_MAPPING[$code])) {
            return self::CODE_MAPPING[$code];
        }

        if (str_starts_with($code, 'WORKFLOW_')) {
            return $code;
        }

        if (str_starts_with($code, 'CIRCUIT_')) {
            return 'WORKFLOW_' . substr($code, strlen('CIRCUIT_'));
        }

        return 'WORKFLOW_' . $code;
    }

    public function resolveWorkflowForCircuit(AdministrativeCircuit $circuit): Workflow
    {
        $workflowCode = $this->resolveWorkflowCode($circuit);
        $workflow = $this->entityManager->getRepository(Workflow::class)->findOneBy(['code' => $workflowCode]);
        if ($workflow !== null && !$workflow->getSteps()->isEmpty()) {
            return $workflow;
        }

        return $this->syncCircuitToWorkflow($circuit);
    }

    /**
     * Synchronise un circuit administratif du back-office (AdministrativeCircuit / CircuitStep)
     * vers le moteur d'exécution de workflow (Workflow / WorkflowStep / WorkflowTransition)
     * sans corrompre les instances de workflow déjà actives (S3.11).
     */
    public function syncCircuitToWorkflow(AdministrativeCircuit $circuit): Workflow
    {
        $workflowCode = $this->resolveWorkflowCode($circuit);
        $workflow = $this->entityManager->getRepository(Workflow::class)->findOneBy(['code' => $workflowCode]);

        if ($workflow === null) {
            $workflow = new Workflow($workflowCode, $circuit->getLabel(), $circuit->getDescription());
            $workflow->setActive($circuit->isActive());
            $this->entityManager->persist($workflow);
        } else {
            $workflow->setName($circuit->getLabel());
            $workflow->setDescription($circuit->getDescription());
            $workflow->setActive($circuit->isActive());
        }

        /** @var list<CircuitStep> $circuitSteps */
        $circuitSteps = $circuit->getSteps()->toArray();
        usort($circuitSteps, static fn (CircuitStep $a, CircuitStep $b) => $a->getPosition() <=> $b->getPosition());

        if (empty($circuitSteps)) {
            $this->entityManager->flush();
            return $workflow;
        }

        // Cas 1 : Le workflow ne possède pas encore d'étapes -> génération complète des étapes et transitions
        if ($workflow->getSteps()->isEmpty()) {
            $createdSteps = [];
            foreach ($circuitSteps as $index => $cStep) {
                $pos = $index + 1;
                $stepCode = sprintf('STEP_%02d_%s', $pos, strtoupper(preg_replace('/[^A-Z0-9_]/i', '_', $cStep->getOrganizationUnit()->getCode())));
                $stepName = $cStep->getLabel() ?: ('Étape ' . $pos . ' — ' . $cStep->getOrganizationUnit()->getName());
                $wfStep = new WorkflowStep(
                    $workflow,
                    substr($stepCode, 0, 100),
                    $stepName,
                    $pos,
                    $cStep->getOrganizationUnit(),
                    null,
                    $pos === 1,
                    false
                );
                $wfStep->setStandardDurationHours($cStep->getStandardDurationHours() ?? 48);
                $this->entityManager->persist($wfStep);
                $createdSteps[] = $wfStep;
            }

            $finalPos = count($createdSteps) + 1;
            $lastUnit = end($circuitSteps)->getOrganizationUnit();
            $finalStep = new WorkflowStep(
                $workflow,
                'ARCHIVED',
                'Clôture & Archivage',
                $finalPos,
                $lastUnit,
                null,
                false,
                true
            );
            $this->entityManager->persist($finalStep);

            // Création automatique des transitions entre étapes consécutives
            $totalSteps = count($createdSteps);
            for ($i = 0; $i < $totalSteps; $i++) {
                $current = $createdSteps[$i];
                $next = ($i + 1 < $totalSteps) ? $createdSteps[$i + 1] : $finalStep;

                // Action sur place : Annotation & Prise en charge
                new WorkflowTransition($workflow, $current, $current, WorkflowAction::Annotate, 'Annoter le dossier', null, 'DOSSIER_ANNOTATE', true);
                new WorkflowTransition($workflow, $current, $current, WorkflowAction::TakeInCharge, 'Prendre en charge', null, 'DOSSIER_TREAT');

                if ($next->isFinal()) {
                    new WorkflowTransition($workflow, $current, $next, WorkflowAction::Validate, 'Valider et clôturer', null, 'DOSSIER_VALIDATE');
                    new WorkflowTransition($workflow, $current, $next, WorkflowAction::Release, 'Sortir du circuit', null, 'DOSSIER_CLOSE');
                    new WorkflowTransition($workflow, $current, $next, WorkflowAction::Close, 'Clôturer le dossier', null, 'DOSSIER_CLOSE');
                } else {
                    new WorkflowTransition($workflow, $current, $next, WorkflowAction::Submit, 'Soumettre à l\'étape suivante', null, 'DOSSIER_FORWARD');
                    new WorkflowTransition($workflow, $current, $next, WorkflowAction::Forward, 'Transmettre à ' . $next->getName(), null, 'DOSSIER_FORWARD');
                    new WorkflowTransition($workflow, $current, $next, WorkflowAction::Orient, 'Orienter vers ' . $next->getName(), null, 'DOSSIER_FORWARD');
                    new WorkflowTransition($workflow, $current, $next, WorkflowAction::Assign, 'Affecter à ' . $next->getName(), null, 'DOSSIER_ASSIGN');
                    new WorkflowTransition($workflow, $current, $next, WorkflowAction::Process, 'Soumettre le traitement', null, 'DOSSIER_TREAT');
                    new WorkflowTransition($workflow, $current, $next, WorkflowAction::Validate, 'Valider et transmettre', null, 'DOSSIER_VALIDATE');
                }

                if ($i > 0) {
                    $prev = $createdSteps[$i - 1];
                    new WorkflowTransition($workflow, $current, $prev, WorkflowAction::Return, 'Retourner à ' . $prev->getName(), null, 'DOSSIER_FORWARD', true, true);
                    new WorkflowTransition($workflow, $current, $prev, WorkflowAction::Reject, 'Rejeter vers ' . $prev->getName(), null, 'DOSSIER_VALIDATE', true, true);
                }
            }

            $this->entityManager->flush();
            return $workflow;
        }

        // Cas 2 : Le workflow existe déjà -> mise à jour non destructive des étapes existantes (structures, libellés, délais)
        /** @var list<WorkflowStep> $existingSteps */
        $existingSteps = array_values(array_filter(
            $workflow->getSteps()->toArray(),
            static fn (WorkflowStep $s) => !$s->isFinal()
        ));
        usort($existingSteps, static fn (WorkflowStep $a, WorkflowStep $b) => $a->getPosition() <=> $b->getPosition());

        foreach ($circuitSteps as $index => $cStep) {
            if (isset($existingSteps[$index])) {
                $wfStep = $existingSteps[$index];
                $wfStep->setTargetStructure($cStep->getOrganizationUnit());
                if ($cStep->getLabel() !== null && $cStep->getLabel() !== '') {
                    $wfStep->setName($cStep->getLabel());
                }
                if ($cStep->getStandardDurationHours() !== null) {
                    $wfStep->setStandardDurationHours($cStep->getStandardDurationHours());
                }
            }
        }

        $this->entityManager->flush();
        return $workflow;
    }
}

