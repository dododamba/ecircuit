<?php

namespace App\Command;

use App\Entity\AccessPermission;
use App\Entity\AccessRole;
use App\Entity\AdministrativeCircuit;
use App\Entity\CircuitStep;
use App\Entity\OrganizationUnit;
use App\Entity\ReferenceItem;
use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use App\Entity\WorkflowTransition;
use App\Enum\ReferenceCategory;
use App\Enum\StructureType;
use App\Enum\WorkflowAction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'mci:seed', description: 'Installs the MCI e-Circuit MVP reference and bootstrap data.')]
final class SeedMciCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        #[Autowire('%env(default::MCI_BOOTSTRAP_ADMIN_EMAIL)%')]
        private ?string $adminEmail = null,
        #[Autowire('%env(default::MCI_BOOTSTRAP_ADMIN_PASSWORD)%')]
        private ?string $adminPassword = null
    ) {
    }

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Initialisation des données de référence MCI e-Circuit');

        // 1. Permissions granulaires
        $permissionDefs = [
            // Authentification
            'AUTH_LOGIN' => ['Connexion au système', 'Authentification'],
            'AUTH_LOGOUT' => ['Déconnexion', 'Authentification'],

            // Utilisateurs
            'USER_VIEW' => ['Consulter les utilisateurs', 'Utilisateurs'],
            'USER_CREATE' => ['Créer un compte utilisateur', 'Utilisateurs'],
            'USER_UPDATE' => ['Modifier un utilisateur', 'Utilisateurs'],
            'USER_ACTIVATE' => ['Activer un compte utilisateur', 'Utilisateurs'],
            'USER_DEACTIVATE' => ['Désactiver un compte utilisateur', 'Utilisateurs'],
            'USER_RESET_PASSWORD' => ['Réinitialiser le mot de passe', 'Utilisateurs'],

            // Rôles & Permissions
            'ROLE_VIEW' => ['Consulter les rôles', 'Accès'],
            'ROLE_CREATE' => ['Créer un rôle', 'Accès'],
            'ROLE_UPDATE' => ['Modifier un rôle', 'Accès'],
            'ROLE_EDIT' => ['Modifier un rôle', 'Accès'],
            'PERMISSION_VIEW' => ['Consulter les permissions', 'Accès'],
            'PERMISSION_ASSIGN' => ['Affecter des permissions aux rôles', 'Accès'],

            // Structures
            'STRUCTURE_VIEW' => ['Consulter les structures administratives', 'Organisation'],
            'STRUCTURE_CREATE' => ['Créer une structure', 'Organisation'],
            'STRUCTURE_UPDATE' => ['Modifier une structure', 'Organisation'],

            // Référentiels
            'REFERENCE_VIEW' => ['Consulter les référentiels métiers', 'Référentiels'],
            'REFERENCE_CREATE' => ['Créer un élément de référentiel', 'Référentiels'],
            'REFERENCE_UPDATE' => ['Modifier un élément de référentiel', 'Référentiels'],

            // Circuits
            'CIRCUIT_VIEW' => ['Consulter les circuits administratifs', 'Circuits'],
            'CIRCUIT_CREATE' => ['Créer un circuit administratif', 'Circuits'],
            'CIRCUIT_UPDATE' => ['Modifier un circuit administratif', 'Circuits'],

            // Audit
            'AUDIT_VIEW' => ['Consulter les journaux d’audit', 'Audit'],

            // Courrier Entrant & Sortant
            'COURRIER_VIEW' => ['Consulter les courriers', 'Courrier'],
            'COURRIER_CREATE' => ['Enregistrer un courrier entrant', 'Courrier'],
            'COURRIER_EDIT' => ['Modifier un courrier', 'Courrier'],
            'COURRIER_TRANSMIT' => ['Transmettre un courrier', 'Courrier'],

            // Dossiers
            'DOSSIER_VIEW' => ['Consulter les courriers et dossiers', 'Dossiers'],
            'DOSSIER_CREATE' => ['Créer / enregistrer un dossier', 'Dossiers'],
            'DOSSIER_EDIT' => ['Modifier un dossier', 'Dossiers'],
            'DOSSIER_ASSIGN' => ['Affecter un dossier à une structure ou un agent', 'Dossiers'],
            'DOSSIER_CLOSE' => ['Clôturer un dossier', 'Dossiers'],
            'DOSSIER_ARCHIVE' => ['Archiver un dossier', 'Dossiers'],
            'DOSSIER_ANNOTATE' => ['Annoter un dossier', 'Dossiers'],
            'DOSSIER_FORWARD' => ['Transmettre / orienter un dossier', 'Dossiers'],
            'DOSSIER_TREAT' => ['Traiter un dossier', 'Dossiers'],
            'DOSSIER_VALIDATE' => ['Valider un dossier', 'Dossiers'],
            'DOSSIER_SIGN' => ['Signer un document / dossier', 'Dossiers'],

            // Documents
            'DOCUMENT_VIEW' => ['Consulter les documents et pièces jointes', 'Documents'],
            'DOCUMENT_UPLOAD' => ['Téléverser des pièces et documents', 'Documents'],
            'DOCUMENT_DOWNLOAD' => ['Télécharger les documents', 'Documents'],
            'DOCUMENT_DELETE' => ['Supprimer un document', 'Documents'],
        ];

        $permissions = [];
        foreach ($permissionDefs as $code => [$label, $module]) {
            $permissions[$code] = $this->upsertPermission($code, $label, $module);
        }

        // 2. Rôles MVP
        $roleDefs = [
            'ADMINISTRATEUR' => ['Administrateur', 'Administration technique et fonctionnelle complète de la plateforme'],
            'AGENT_CABINET' => ['Agent Cabinet', 'Réception, enregistrement, numérisation et suivi des courriers au Cabinet'],
            'MINISTRE' => ['Ministre', 'Consultation, annotation, orientation, validation et signature ministérielle'],
            'SECRETAIRE_GENERAL' => ['Secrétaire Général', 'Réception, analyse, orientation vers directions générales et suivi'],
            'DIRECTEUR_GENERAL' => ['Directeur Général', 'Orientation vers les directions techniques et supervision'],
            'RESPONSABLE_DIRECTION' => ['Responsable de Direction', 'Affectation aux agents, traitement et validation technique'],
            'AGENT_TRAITEMENT' => ['Agent de traitement', 'Traitement opérationnel des dossiers et transmission hiérarchique'],
            'SERVICE_COURRIER' => ['Service Courrier', 'Réception, numérisation, enregistrement et transmission initiale des courriers'],
        ];

        $roles = [];
        foreach ($roleDefs as $code => [$label, $desc]) {
            $roles[$code] = $this->upsertRole($code, $label, $desc);
        }

        // 3. Matrice Rôles-Permissions
        // Administrateur a TOUT
        foreach ($permissions as $permission) {
            $roles['ADMINISTRATEUR']->grant($permission);
        }

        // Service Courrier
        foreach ([
            'AUTH_LOGIN', 'AUTH_LOGOUT', 'STRUCTURE_VIEW', 'REFERENCE_VIEW',
            'COURRIER_VIEW', 'COURRIER_CREATE', 'COURRIER_EDIT', 'COURRIER_TRANSMIT',
            'DOSSIER_VIEW', 'DOSSIER_CREATE',
            'DOCUMENT_VIEW', 'DOCUMENT_UPLOAD', 'DOCUMENT_DOWNLOAD'
        ] as $code) {
            if (isset($permissions[$code])) {
                $roles['SERVICE_COURRIER']->grant($permissions[$code]);
            }
        }

        // Agent Cabinet
        foreach ([
            'AUTH_LOGIN', 'AUTH_LOGOUT', 'STRUCTURE_VIEW', 'REFERENCE_VIEW', 'CIRCUIT_VIEW',
            'COURRIER_VIEW', 'COURRIER_CREATE', 'COURRIER_EDIT', 'COURRIER_TRANSMIT',
            'DOSSIER_VIEW', 'DOSSIER_CREATE', 'DOSSIER_FORWARD', 'DOSSIER_CLOSE',
            'DOCUMENT_VIEW', 'DOCUMENT_UPLOAD', 'DOCUMENT_DOWNLOAD'
        ] as $code) {
            if (isset($permissions[$code])) {
                $roles['AGENT_CABINET']->grant($permissions[$code]);
            }
        }

        // Ministre
        foreach ([
            'AUTH_LOGIN', 'AUTH_LOGOUT', 'STRUCTURE_VIEW', 'REFERENCE_VIEW', 'CIRCUIT_VIEW',
            'COURRIER_VIEW', 'DOSSIER_VIEW', 'DOSSIER_ANNOTATE', 'DOSSIER_FORWARD', 'DOSSIER_VALIDATE', 'DOSSIER_SIGN',
            'DOCUMENT_VIEW', 'DOCUMENT_DOWNLOAD'
        ] as $code) {
            if (isset($permissions[$code])) {
                $roles['MINISTRE']->grant($permissions[$code]);
            }
        }

        // Secrétaire Général
        foreach ([
            'AUTH_LOGIN', 'AUTH_LOGOUT', 'STRUCTURE_VIEW', 'REFERENCE_VIEW', 'CIRCUIT_VIEW',
            'COURRIER_VIEW', 'DOSSIER_VIEW', 'DOSSIER_ANNOTATE', 'DOSSIER_FORWARD', 'DOSSIER_ASSIGN', 'DOSSIER_VALIDATE', 'DOSSIER_CLOSE',
            'DOCUMENT_VIEW', 'DOCUMENT_DOWNLOAD'
        ] as $code) {
            if (isset($permissions[$code])) {
                $roles['SECRETAIRE_GENERAL']->grant($permissions[$code]);
            }
        }

        // Directeur Général
        foreach ([
            'AUTH_LOGIN', 'AUTH_LOGOUT', 'STRUCTURE_VIEW', 'REFERENCE_VIEW', 'CIRCUIT_VIEW',
            'COURRIER_VIEW', 'DOSSIER_VIEW', 'DOSSIER_ANNOTATE', 'DOSSIER_FORWARD', 'DOSSIER_ASSIGN', 'DOSSIER_VALIDATE',
            'DOCUMENT_VIEW', 'DOCUMENT_DOWNLOAD'
        ] as $code) {
            if (isset($permissions[$code])) {
                $roles['DIRECTEUR_GENERAL']->grant($permissions[$code]);
            }
        }

        // Responsable de Direction
        foreach ([
            'AUTH_LOGIN', 'AUTH_LOGOUT', 'STRUCTURE_VIEW', 'REFERENCE_VIEW', 'CIRCUIT_VIEW',
            'COURRIER_VIEW', 'DOSSIER_VIEW', 'DOSSIER_ANNOTATE', 'DOSSIER_FORWARD', 'DOSSIER_ASSIGN', 'DOSSIER_TREAT', 'DOSSIER_VALIDATE',
            'DOCUMENT_VIEW', 'DOCUMENT_UPLOAD', 'DOCUMENT_DOWNLOAD'
        ] as $code) {
            if (isset($permissions[$code])) {
                $roles['RESPONSABLE_DIRECTION']->grant($permissions[$code]);
            }
        }

        // Agent de Traitement
        foreach ([
            'AUTH_LOGIN', 'AUTH_LOGOUT', 'STRUCTURE_VIEW', 'REFERENCE_VIEW',
            'COURRIER_VIEW', 'DOSSIER_VIEW', 'DOSSIER_TREAT', 'DOSSIER_FORWARD',
            'DOCUMENT_VIEW', 'DOCUMENT_UPLOAD', 'DOCUMENT_DOWNLOAD'
        ] as $code) {
            if (isset($permissions[$code])) {
                $roles['AGENT_TRAITEMENT']->grant($permissions[$code]);
            }
        }

        // 4. Organisation Administrative Hiérarchique
        $ministere = $this->upsertUnit('MINISTERE', 'Ministère du Commerce et de l’Industrie', StructureType::Ministere, null, 1);
        $cabinet = $this->upsertUnit('CABINET', 'Cabinet du Ministre', StructureType::Cabinet, $ministere, 2);
        $sg = $this->upsertUnit('SECRETARIAT_GENERAL', 'Secrétariat Général', StructureType::SecretariatGeneral, $ministere, 3);

        // DG Commerce
        $dgCommerce = $this->upsertUnit('DG_COMMERCE', 'Direction Générale du Commerce', StructureType::DirectionGenerale, $ministere, 4);
        $dirCommerceInterieur = $this->upsertUnit('DIRECTION_COMMERCE_INTERIEUR', 'Direction du Commerce Intérieur', StructureType::Direction, $dgCommerce, 1);
        $serviceReglementation = $this->upsertUnit('SERVICE_REGLEMENTATION', 'Service de la Réglementation et des Normes', StructureType::Service, $dirCommerceInterieur, 1);
        $serviceControle = $this->upsertUnit('SERVICE_CONTROLE_FRAUDES', 'Service du Contrôle et de la Répression des Fraudes', StructureType::Service, $dirCommerceInterieur, 2);

        $dirCommerceExterieur = $this->upsertUnit('DIRECTION_COMMERCE_EXTERIEUR', 'Direction du Commerce Extérieur', StructureType::Direction, $dgCommerce, 2);
        $serviceImportExport = $this->upsertUnit('SERVICE_IMPORT_EXPORT', 'Service des Importations et Exportations', StructureType::Service, $dirCommerceExterieur, 1);

        // DG Industrie & Coopératives
        $dgIndustrie = $this->upsertUnit('DG_INDUSTRIE_COOPERATIVES', 'Direction Générale de l’Industrie et des Coopératives', StructureType::DirectionGenerale, $ministere, 5);
        $dirDevIndustriel = $this->upsertUnit('DIRECTION_DEVELOPPEMENT_INDUSTRIEL', 'Direction du Développement Industriel', StructureType::Direction, $dgIndustrie, 1);
        $servicePromotion = $this->upsertUnit('SERVICE_PROMOTION_INDUSTRIELLE', 'Service de la Promotion Industrielle', StructureType::Service, $dirDevIndustriel, 1);

        $dirCooperatives = $this->upsertUnit('DIRECTION_COOPERATIVES', 'Direction des Organisations Professionnelles et Coopératives', StructureType::Direction, $dgIndustrie, 2);
        $serviceAgrement = $this->upsertUnit('SERVICE_AGREMENT_COOPERATIVES', 'Service de l’Agrément des Coopératives', StructureType::Service, $dirCooperatives, 1);

        // Structures Rattachées & Sous Tutelle
        $anie = $this->upsertUnit('ANIE', 'Agence Nationale des Investissements et des Exportations', StructureType::StructureRattachee, $ministere, 6);
        $azes = $this->upsertUnit('AZES', 'Autorité des Zones Économiques Spéciales', StructureType::StructureRattachee, $ministere, 7);
        $ppp = $this->upsertUnit('CELLULE_PPP', 'Cellule Partenariats Public-Privé', StructureType::StructureRattachee, $ministere, 8);

        // Délégations Provinciales
        $delEst = $this->upsertUnit('DELEGATION_PROV_EST', 'Délégation Provinciale du Commerce et de l’Industrie - Zone Est', StructureType::DelegationProvinciale, $ministere, 9);
        $delOuest = $this->upsertUnit('DELEGATION_PROV_OUEST', 'Délégation Provinciale du Commerce et de l’Industrie - Zone Ouest', StructureType::DelegationProvinciale, $ministere, 10);

        // 5. Référentiels
        // Types de documents
        $this->upsertReferences(ReferenceCategory::DocumentType, [
            'COURRIER_ADMINISTRATIF' => ['Courrier administratif', 'Correspondance administrative générale', null, 1],
            'NOTE' => ['Note', 'Note de service ou note d’information interne', null, 2],
            'LETTRE' => ['Lettre', 'Lettre officielle', null, 3],
            'DEMANDE' => ['Demande', 'Demande formelle d’un usager ou opérateur économique', null, 4],
            'DECISION' => ['Décision', 'Décision administrative ou arrêté', null, 5],
            'RAPPORT' => ['Rapport', 'Rapport d’activité, d’inspection ou de mission', null, 6],
            'PROPOSITION' => ['Proposition', 'Proposition technique ou financière', null, 7],
            'CONVENTION' => ['Convention', 'Convention, protocole d’accord ou traité', null, 8],
            'AUTORISATION' => ['Autorisation', 'Titre d’autorisation légale ou réglementaire', null, 9],
            'CORRESPONDANCE_OFFICIELLE' => ['Correspondance officielle', 'Échange officiel institutionnel', null, 10],
        ]);

        // Types de courriers
        $this->upsertReferences(ReferenceCategory::MailType, [
            'COURRIER_ENTRANT' => ['Courrier entrant', 'Courrier reçu de l’extérieur', 'blue', 1],
            'COURRIER_SORTANT' => ['Courrier sortant', 'Courrier émis vers l’extérieur', 'green', 2],
            'COURRIER_INTERNE' => ['Courrier interne', 'Circulation interne au Ministère', 'gray', 3],
            'NOTE_ASCENDANTE' => ['Note ascendante', 'Note remontant vers la hiérarchie', 'purple', 4],
            'NOTE_DESCENDANTE' => ['Note descendante', 'Instruction descendant de la hiérarchie', 'indigo', 5],
            'CORRESPONDANCE_PARTENAIRE' => ['Correspondance partenaire', 'Échange avec institutions partenaires ou bailleurs', 'teal', 6],
        ]);

        // Types de demandes
        $this->upsertReferences(ReferenceCategory::RequestType, [
            'AUTORISATION_IMPORTATION' => ['Autorisation d’importation', 'Demande relative à l’importation de marchandises', null, 1],
            'AUTORISATION_EXPORTATION' => ['Autorisation d’exportation', 'Demande d’exportation de produits', null, 2],
            'AUTORISATION_OUVERTURE_INDUSTRIE' => ['Autorisation d’ouverture d’industrie', 'Agrément pour installation d’unité industrielle', null, 3],
            'CREATION_COOPERATIVE' => ['Création de coopérative', 'Dossier de constitution et agrément de société coopérative', null, 4],
            'DEMANDE_CONGE' => ['Demande de congé', 'Procédure RH de congé annuel ou exceptionnel', null, 5],
            'DEMANDE_ABSENCE' => ['Demande d’absence', 'Autorisation d’absence ponctuelle', null, 6],
            'DEMANDE_TRANSFERT' => ['Demande de transfert', 'Mutation ou affectation de personnel', null, 7],
            'MISE_EN_DISPONIBILITE' => ['Mise en disponibilité', 'Demande statutaire de mise en disponibilité', null, 8],
            'DEMANDE_PARTENARIAT' => ['Demande de partenariat', 'Partenariat institutionnel ou économique', null, 9],
            'DEMANDE_PPP' => ['Demande PPP', 'Projet sous forme de Partenariat Public-Privé', null, 10],
            'CREATION_ENTREPRISE' => ['Création d’entreprise', 'Dossier de formalisation d’entreprise', null, 11],
            'DEMANDE_ZES' => ['Demande relative aux zones économiques spéciales', 'Implantation ou régime incitatif ZES', null, 12],
        ]);

        // Statuts de dossiers
        $this->upsertReferences(ReferenceCategory::DossierStatus, [
            'RECEIVED' => ['Reçu', 'Dossier physique ou numérique reçu', '#64748b', 1],
            'REGISTERED' => ['Enregistré', 'Numéro unique attribué et pièces numérisées', '#0284c7', 2],
            'SUBMITTED' => ['Soumis', 'Soumis pour orientation hiérarchique', '#2563eb', 3],
            'ANNOTATED' => ['Annoté', 'Instructions et orientations apportées', '#7c3aed', 4],
            'ASSIGNED' => ['Affecté', 'Affecté à une direction ou un agent pour traitement', '#0891b2', 5],
            'IN_PROGRESS' => ['En cours', 'Traitement technique en cours', '#d97706', 6],
            'PENDING' => ['En attente', 'En attente de pièces complémentaires ou d’avis externe', '#ea580c', 7],
            'FORWARDED' => ['Transmis', 'Transmis vers le niveau suivant du circuit', '#4f46e5', 8],
            'TO_SIGN' => ['À signer', 'Projet finalisé prêt pour visa ou signature', '#9333ea', 9],
            'SIGNED' => ['Signé', 'Document officiel signé', '#16a34a', 10],
            'COMPLETED' => ['Terminé', 'Traitement entièrement abouti', '#059669', 11],
            'RELEASED' => ['Libéré', 'Dossier remis ou expédié au destinataire', '#0d9488', 12],
            'ARCHIVED' => ['Archivé', 'Dossier classé et archivé définitivement', '#475569', 13],
        ]);

        // Priorités
        $this->upsertReferences(ReferenceCategory::Priority, [
            'NORMALE' => ['Normale', 'Délai usuel de traitement', 'gray', 1],
            'URGENTE' => ['Urgente', 'Traitement prioritaire sous 48 heures', 'orange', 2],
            'TRES_URGENTE' => ['Très urgente', 'Traitement immédiat sous 24 heures', 'red', 3],
        ]);

        // Motifs de transmission
        $this->upsertReferences(ReferenceCategory::TransmissionReason, [
            'TRAITEMENT' => ['Pour traitement', 'Exécution et instruction du dossier', null, 1],
            'AVIS' => ['Pour avis', 'Avis technique ou juridique requis', null, 2],
            'ETUDE' => ['Pour étude', 'Analyse approfondie et rapport', null, 3],
            'INFORMATION' => ['Pour information', 'Prise de connaissance sans action requise', null, 4],
            'VALIDATION' => ['Pour validation', 'Examen pour validation intermédiaire', null, 5],
            'DECISION' => ['Pour décision', 'Arbitrage ou décision d’autorité', null, 6],
            'SIGNATURE' => ['Pour signature', 'Apposition de visa ou de signature officielle', null, 7],
            'EXECUTION' => ['Pour exécution', 'Mise en œuvre effective', null, 8],
            'SUIVI' => ['Pour suivi', 'Suivi de l’avancement et reporting', null, 9],
        ]);

        // 6. Circuits administratifs configurables
        // Circuit Cabinet standard : CABINET -> MINISTRE -> SG -> DG COMMERCE -> DIRECTION COMMERCE INTÉRIEUR -> SERVICE RÉGLEMENTATION
        $circuitCabinet = $this->entityManager->getRepository(AdministrativeCircuit::class)->findOneBy(['code' => 'CIRCUIT_CABINET_STANDARD'])
            ?? new AdministrativeCircuit('CIRCUIT_CABINET_STANDARD', 'Circuit Cabinet standard', 'Circuit type pour les courriers arrivant au Cabinet du Ministre');
        $this->entityManager->persist($circuitCabinet);

        if ($circuitCabinet->getSteps()->isEmpty()) {
            $stepsCabinet = [
                [$cabinet, 'Réception, enregistrement et numérisation'],
                [$ministere, 'Annotation et orientation ministérielle'],
                [$sg, 'Orientation vers direction générale'],
                [$dgCommerce, 'Orientation vers direction technique'],
                [$dirCommerceInterieur, 'Affectation au service compétent'],
                [$serviceReglementation, 'Instruction et traitement technique'],
            ];
            foreach ($stepsCabinet as $pos => [$unit, $lbl]) {
                new CircuitStep($circuitCabinet, $unit, $pos + 1, $lbl);
            }
        }

        // Circuit Secrétariat Général standard : SG -> DG COMMERCE -> DIRECTION COMMERCE INTÉRIEUR -> SERVICE RÉGLEMENTATION
        $circuitSG = $this->entityManager->getRepository(AdministrativeCircuit::class)->findOneBy(['code' => 'CIRCUIT_SG_STANDARD'])
            ?? new AdministrativeCircuit('CIRCUIT_SG_STANDARD', 'Circuit Secrétariat Général standard', 'Circuit type pour les courriers réceptionnés au Secrétariat Général');
        $this->entityManager->persist($circuitSG);

        if ($circuitSG->getSteps()->isEmpty()) {
            $stepsSG = [
                [$sg, 'Réception, analyse et orientation générale'],
                [$dgCommerce, 'Orientation vers direction technique'],
                [$dirCommerceInterieur, 'Affectation au service'],
                [$serviceReglementation, 'Traitement technique opérationnel'],
            ];
            foreach ($stepsSG as $pos => [$unit, $lbl]) {
                new CircuitStep($circuitSG, $unit, $pos + 1, $lbl);
            }
        }

        // Circuit Provincial (S3.12)
        $circuitProvincial = $this->entityManager->getRepository(AdministrativeCircuit::class)->findOneBy(['code' => 'CIRCUIT_PROVINCIAL'])
            ?? new AdministrativeCircuit('CIRCUIT_PROVINCIAL', 'Circuit Délégation Provinciale', 'Circuit déconcentré propre aux Délégations Provinciales du Commerce et de l’Industrie');
        $this->entityManager->persist($circuitProvincial);

        if ($circuitProvincial->getSteps()->isEmpty()) {
            $stepsProv = [
                [$delEst, 'Réception Délégation Provinciale'],
                [$delEst, 'Orientation Délégué Provincial'],
                [$delEst, 'Instruction Service Provincial'],
                [$delEst, 'Validation Service Provincial'],
                [$delEst, 'Validation Finale Délégué Provincial'],
            ];
            foreach ($stepsProv as $pos => [$unit, $lbl]) {
                new CircuitStep($circuitProvincial, $unit, $pos + 1, $lbl);
            }
        }

        // 6.2 Moteur de Workflow - Circuit Cabinet standard (Module 7)
        $wfCabinet = $this->entityManager->getRepository(Workflow::class)->findOneBy(['code' => 'WORKFLOW_CABINET_STANDARD'])
            ?? new Workflow('WORKFLOW_CABINET_STANDARD', 'Circuit Cabinet standard', 'Workflow institutionnel complet de traitement des courriers arrivant au Cabinet du Ministre');
        $this->entityManager->persist($wfCabinet);

        if ($wfCabinet->getSteps()->isEmpty()) {
            $step1 = new WorkflowStep($wfCabinet, 'CABINET_RECEPTION', 'Réception Cabinet', 1, $cabinet, $roles['AGENT_CABINET'], true, false);
            $step1->setStandardDurationHours(24);

            $step2 = new WorkflowStep($wfCabinet, 'MINISTRE_REVIEW', 'Revue & Annotation Ministre', 2, $ministere, $roles['MINISTRE'], false, false);
            $step2->setStandardDurationHours(48);

            $step3 = new WorkflowStep($wfCabinet, 'SG_REVIEW', 'Orientation Secrétaire Général', 3, $sg, $roles['SECRETAIRE_GENERAL'], false, false);
            $step3->setStandardDurationHours(48);

            $step4 = new WorkflowStep($wfCabinet, 'DG_REVIEW', 'Orientation Direction Générale', 4, $dgCommerce, $roles['DIRECTEUR_GENERAL'], false, false);
            $step4->setStandardDurationHours(48);

            $step5 = new WorkflowStep($wfCabinet, 'DIRECTION_PROCESSING', 'Affectation Direction', 5, $dirCommerceInterieur, $roles['RESPONSABLE_DIRECTION'], false, false);
            $step5->setStandardDurationHours(48);

            $step6 = new WorkflowStep($wfCabinet, 'AGENT_PROCESSING', 'Instruction Opérationnelle', 6, $serviceReglementation, $roles['AGENT_TRAITEMENT'], false, false);
            $step6->setStandardDurationHours(72);

            $step7 = new WorkflowStep($wfCabinet, 'DIRECTION_VALIDATION', 'Validation Direction', 7, $dirCommerceInterieur, $roles['RESPONSABLE_DIRECTION'], false, false);
            $step7->setStandardDurationHours(48);

            $step8 = new WorkflowStep($wfCabinet, 'DG_VALIDATION', 'Validation Direction Générale', 8, $dgCommerce, $roles['DIRECTEUR_GENERAL'], false, false);
            $step8->setStandardDurationHours(48);

            $step9 = new WorkflowStep($wfCabinet, 'SG_VALIDATION', 'Validation Secrétaire Général', 9, $sg, $roles['SECRETAIRE_GENERAL'], false, false);
            $step9->setStandardDurationHours(48);

            $step10 = new WorkflowStep($wfCabinet, 'MINISTRE_SIGNATURE', 'Signature Ministre', 10, $ministere, $roles['MINISTRE'], false, false);
            $step10->setStandardDurationHours(48);

            $step11 = new WorkflowStep($wfCabinet, 'CABINET_RELEASE', 'Sortie Cabinet', 11, $cabinet, $roles['AGENT_CABINET'], false, false);
            $step11->setStandardDurationHours(24);

            $step12 = new WorkflowStep($wfCabinet, 'ARCHIVED', 'Archives Ministérielles', 12, $cabinet, $roles['ADMINISTRATEUR'], false, true);

            // Transitions
            // Cabinet -> Ministre
            new WorkflowTransition($wfCabinet, $step1, $step2, WorkflowAction::Submit, 'Soumettre au Ministre', $roles['AGENT_CABINET'], 'DOSSIER_FORWARD');

            // Ministre -> Annoter sur place
            new WorkflowTransition($wfCabinet, $step2, $step2, WorkflowAction::Annotate, 'Annoter le dossier', $roles['MINISTRE'], 'DOSSIER_ANNOTATE', true);

            // Ministre -> SG (SEULE DESTINATION AUTORISÉE DANS LE CIRCUIT STANDARD PAR LA RÈGLE RB-005)
            new WorkflowTransition($wfCabinet, $step2, $step3, WorkflowAction::Orient, 'Orienter vers le Secrétariat Général', $roles['MINISTRE'], 'DOSSIER_FORWARD');
            new WorkflowTransition($wfCabinet, $step2, $step3, WorkflowAction::Forward, 'Transmettre au Secrétaire Général', $roles['MINISTRE'], 'DOSSIER_FORWARD');

            // SG -> DG
            new WorkflowTransition($wfCabinet, $step3, $step4, WorkflowAction::Forward, 'Transmettre à la Direction Générale', $roles['SECRETAIRE_GENERAL'], 'DOSSIER_FORWARD');
            new WorkflowTransition($wfCabinet, $step3, $step4, WorkflowAction::Orient, 'Orienter vers la DG Commerce', $roles['SECRETAIRE_GENERAL'], 'DOSSIER_FORWARD');

            // DG -> Direction
            new WorkflowTransition($wfCabinet, $step4, $step5, WorkflowAction::Forward, 'Transmettre à la Direction compétente', $roles['DIRECTEUR_GENERAL'], 'DOSSIER_FORWARD');

            // Direction -> Agent
            new WorkflowTransition($wfCabinet, $step5, $step6, WorkflowAction::Assign, 'Affecter à l\'agent instructeur', $roles['RESPONSABLE_DIRECTION'], 'DOSSIER_ASSIGN');

            // Agent : Prise en charge & traitement
            new WorkflowTransition($wfCabinet, $step6, $step6, WorkflowAction::TakeInCharge, 'Prendre en charge le dossier', $roles['AGENT_TRAITEMENT'], 'DOSSIER_TREAT');
            new WorkflowTransition($wfCabinet, $step6, $step7, WorkflowAction::Process, 'Soumettre les conclusions pour validation', $roles['AGENT_TRAITEMENT'], 'DOSSIER_TREAT');
            new WorkflowTransition($wfCabinet, $step6, $step7, WorkflowAction::Submit, 'Transmettre au responsable', $roles['AGENT_TRAITEMENT'], 'DOSSIER_TREAT');

            // Remontée : Direction validation
            new WorkflowTransition($wfCabinet, $step7, $step6, WorkflowAction::Return, 'Retourner à l\'agent pour correction', $roles['RESPONSABLE_DIRECTION'], 'DOSSIER_FORWARD', true, true);
            new WorkflowTransition($wfCabinet, $step7, $step6, WorkflowAction::Reject, 'Rejeter les propositions', $roles['RESPONSABLE_DIRECTION'], 'DOSSIER_VALIDATE', true, true);
            new WorkflowTransition($wfCabinet, $step7, $step8, WorkflowAction::Validate, 'Valider et transmettre à la DG', $roles['RESPONSABLE_DIRECTION'], 'DOSSIER_VALIDATE');

            // Remontée : DG validation
            new WorkflowTransition($wfCabinet, $step8, $step7, WorkflowAction::Return, 'Retourner à la Direction', $roles['DIRECTEUR_GENERAL'], 'DOSSIER_FORWARD', true, true);
            new WorkflowTransition($wfCabinet, $step8, $step9, WorkflowAction::Validate, 'Valider et transmettre au SG', $roles['DIRECTEUR_GENERAL'], 'DOSSIER_VALIDATE');

            // Remontée : SG validation
            new WorkflowTransition($wfCabinet, $step9, $step8, WorkflowAction::Return, 'Retourner à la DG', $roles['SECRETAIRE_GENERAL'], 'DOSSIER_FORWARD', true, true);
            new WorkflowTransition($wfCabinet, $step9, $step10, WorkflowAction::Validate, 'Valider et soumettre au Ministre', $roles['SECRETAIRE_GENERAL'], 'DOSSIER_VALIDATE');

            // Signature ministérielle
            new WorkflowTransition($wfCabinet, $step10, $step9, WorkflowAction::Return, 'Retourner au SG pour réexamen', $roles['MINISTRE'], 'DOSSIER_FORWARD', true, true);
            new WorkflowTransition($wfCabinet, $step10, $step11, WorkflowAction::Validate, 'Viser / Signer et transmettre au Cabinet', $roles['MINISTRE'], 'DOSSIER_SIGN');

            // Sortie & Archivage Cabinet
            new WorkflowTransition($wfCabinet, $step11, $step12, WorkflowAction::Release, 'Enregistrer la sortie du dossier', $roles['AGENT_CABINET'], 'DOSSIER_CLOSE');
            new WorkflowTransition($wfCabinet, $step11, $step12, WorkflowAction::Close, 'Clôturer le dossier', $roles['AGENT_CABINET'], 'DOSSIER_CLOSE');
        }

        // 6.3 Moteur de Workflow - Circuit Secrétariat Général standard (S3.03)
        $wfSG = $this->entityManager->getRepository(Workflow::class)->findOneBy(['code' => 'WORKFLOW_SG_STANDARD'])
            ?? new Workflow('WORKFLOW_SG_STANDARD', 'Circuit Secrétariat Général standard', 'Workflow institutionnel des courriers et dossiers réceptionnés directement au Secrétariat Général');
        $this->entityManager->persist($wfSG);

        if ($wfSG->getSteps()->isEmpty()) {
            $sgStep1 = new WorkflowStep($wfSG, 'SG_RECEPTION', 'Réception Secrétariat Général', 1, $sg, $roles['SECRETAIRE_GENERAL'], true, false);
            $sgStep1->setStandardDurationHours(24);

            $sgStep2 = new WorkflowStep($wfSG, 'SG_REVIEW', 'Orientation Secrétaire Général', 2, $sg, $roles['SECRETAIRE_GENERAL'], false, false);
            $sgStep2->setStandardDurationHours(48);

            $sgStep3 = new WorkflowStep($wfSG, 'DG_REVIEW', 'Orientation Direction Générale', 3, $dgCommerce, $roles['DIRECTEUR_GENERAL'], false, false);
            $sgStep3->setStandardDurationHours(48);

            $sgStep4 = new WorkflowStep($wfSG, 'DIRECTION_PROCESSING', 'Affectation Direction', 4, $dirCommerceInterieur, $roles['RESPONSABLE_DIRECTION'], false, false);
            $sgStep4->setStandardDurationHours(48);

            $sgStep5 = new WorkflowStep($wfSG, 'AGENT_PROCESSING', 'Instruction Opérationnelle', 5, $serviceReglementation, $roles['AGENT_TRAITEMENT'], false, false);
            $sgStep5->setStandardDurationHours(72);

            $sgStep6 = new WorkflowStep($wfSG, 'DIRECTION_VALIDATION', 'Validation Direction', 6, $dirCommerceInterieur, $roles['RESPONSABLE_DIRECTION'], false, false);
            $sgStep6->setStandardDurationHours(48);

            $sgStep7 = new WorkflowStep($wfSG, 'DG_VALIDATION', 'Validation Direction Générale', 7, $dgCommerce, $roles['DIRECTEUR_GENERAL'], false, false);
            $sgStep7->setStandardDurationHours(48);

            $sgStep8 = new WorkflowStep($wfSG, 'SG_VALIDATION', 'Validation Secrétaire Général', 8, $sg, $roles['SECRETAIRE_GENERAL'], false, false);
            $sgStep8->setStandardDurationHours(48);

            $sgStep9 = new WorkflowStep($wfSG, 'MINISTRE_SIGNATURE', 'Signature Ministre', 9, $ministere, $roles['MINISTRE'], false, false);
            $sgStep9->setStandardDurationHours(48);

            $sgStep10 = new WorkflowStep($wfSG, 'SG_RELEASE', 'Sortie & Expédition SG', 10, $sg, $roles['SECRETAIRE_GENERAL'], false, false);
            $sgStep10->setStandardDurationHours(24);

            $sgStep11 = new WorkflowStep($wfSG, 'ARCHIVED', 'Archives Ministérielles', 11, $sg, $roles['ADMINISTRATEUR'], false, true);

            new WorkflowTransition($wfSG, $sgStep1, $sgStep2, WorkflowAction::Submit, 'Soumettre au Secrétaire Général', null, 'DOSSIER_FORWARD');
            new WorkflowTransition($wfSG, $sgStep1, $sgStep2, WorkflowAction::Forward, 'Transmettre pour examen SG', null, 'DOSSIER_FORWARD');
            new WorkflowTransition($wfSG, $sgStep2, $sgStep2, WorkflowAction::Annotate, 'Annoter le dossier', $roles['SECRETAIRE_GENERAL'], 'DOSSIER_ANNOTATE', true);
            new WorkflowTransition($wfSG, $sgStep2, $sgStep3, WorkflowAction::Orient, 'Orienter vers la Direction Générale', $roles['SECRETAIRE_GENERAL'], 'DOSSIER_FORWARD');
            new WorkflowTransition($wfSG, $sgStep2, $sgStep3, WorkflowAction::Forward, 'Transmettre à la Direction Générale', $roles['SECRETAIRE_GENERAL'], 'DOSSIER_FORWARD');
            new WorkflowTransition($wfSG, $sgStep3, $sgStep4, WorkflowAction::Forward, 'Transmettre à la Direction compétente', $roles['DIRECTEUR_GENERAL'], 'DOSSIER_FORWARD');
            new WorkflowTransition($wfSG, $sgStep3, $sgStep4, WorkflowAction::Orient, 'Orienter vers la Direction compétente', $roles['DIRECTEUR_GENERAL'], 'DOSSIER_FORWARD');
            new WorkflowTransition($wfSG, $sgStep4, $sgStep5, WorkflowAction::Assign, 'Affecter à l\'agent instructeur', $roles['RESPONSABLE_DIRECTION'], 'DOSSIER_ASSIGN');
            new WorkflowTransition($wfSG, $sgStep5, $sgStep5, WorkflowAction::TakeInCharge, 'Prendre en charge le dossier', $roles['AGENT_TRAITEMENT'], 'DOSSIER_TREAT');
            new WorkflowTransition($wfSG, $sgStep5, $sgStep6, WorkflowAction::Process, 'Soumettre les conclusions pour validation', $roles['AGENT_TRAITEMENT'], 'DOSSIER_TREAT');
            new WorkflowTransition($wfSG, $sgStep5, $sgStep6, WorkflowAction::Submit, 'Transmettre au responsable', $roles['AGENT_TRAITEMENT'], 'DOSSIER_TREAT');
            new WorkflowTransition($wfSG, $sgStep6, $sgStep5, WorkflowAction::Return, 'Retourner à l\'agent pour correction', $roles['RESPONSABLE_DIRECTION'], 'DOSSIER_FORWARD', true, true);
            new WorkflowTransition($wfSG, $sgStep6, $sgStep5, WorkflowAction::Reject, 'Rejeter les propositions', $roles['RESPONSABLE_DIRECTION'], 'DOSSIER_VALIDATE', true, true);
            new WorkflowTransition($wfSG, $sgStep6, $sgStep7, WorkflowAction::Validate, 'Valider et transmettre à la DG', $roles['RESPONSABLE_DIRECTION'], 'DOSSIER_VALIDATE');
            new WorkflowTransition($wfSG, $sgStep7, $sgStep6, WorkflowAction::Return, 'Retourner à la Direction', $roles['DIRECTEUR_GENERAL'], 'DOSSIER_FORWARD', true, true);
            new WorkflowTransition($wfSG, $sgStep7, $sgStep8, WorkflowAction::Validate, 'Valider et transmettre au SG', $roles['DIRECTEUR_GENERAL'], 'DOSSIER_VALIDATE');
            new WorkflowTransition($wfSG, $sgStep8, $sgStep7, WorkflowAction::Return, 'Retourner à la DG', $roles['SECRETAIRE_GENERAL'], 'DOSSIER_FORWARD', true, true);
            new WorkflowTransition($wfSG, $sgStep8, $sgStep9, WorkflowAction::Validate, 'Valider et soumettre au Ministre', $roles['SECRETAIRE_GENERAL'], 'DOSSIER_VALIDATE');
            new WorkflowTransition($wfSG, $sgStep8, $sgStep10, WorkflowAction::Forward, 'Valider et préparer la sortie SG', $roles['SECRETAIRE_GENERAL'], 'DOSSIER_VALIDATE');
            new WorkflowTransition($wfSG, $sgStep9, $sgStep8, WorkflowAction::Return, 'Retourner au SG pour réexamen', $roles['MINISTRE'], 'DOSSIER_FORWARD', true, true);
            new WorkflowTransition($wfSG, $sgStep9, $sgStep10, WorkflowAction::Validate, 'Signer et retourner au SG pour sortie', $roles['MINISTRE'], 'DOSSIER_SIGN');
            new WorkflowTransition($wfSG, $sgStep10, $sgStep11, WorkflowAction::Release, 'Enregistrer la sortie du dossier', null, 'DOSSIER_CLOSE');
            new WorkflowTransition($wfSG, $sgStep10, $sgStep11, WorkflowAction::Close, 'Clôturer et archiver le dossier', null, 'DOSSIER_CLOSE');
        }

        // 6.4 Moteur de Workflow - Circuit Délégation Provinciale (S3.12)
        $wfProv = $this->entityManager->getRepository(Workflow::class)->findOneBy(['code' => 'WORKFLOW_PROVINCIAL'])
            ?? new Workflow('WORKFLOW_PROVINCIAL', 'Circuit Délégation Provinciale', 'Workflow déconcentré dédié au traitement local dans les Délégations Provinciales');
        $this->entityManager->persist($wfProv);

        if ($wfProv->getSteps()->isEmpty()) {
            $prStep1 = new WorkflowStep($wfProv, 'PROVINCIAL_RECEPTION', 'Réception Délégation Provinciale', 1, $delEst, null, true, false);
            $prStep1->setStandardDurationHours(24);

            $prStep2 = new WorkflowStep($wfProv, 'SG_PROVINCIAL_REVIEW', 'Orientation Délégué Provincial', 2, $delEst, null, false, false);
            $prStep2->setStandardDurationHours(48);

            $prStep3 = new WorkflowStep($wfProv, 'DIRECTION_PROCESSING', 'Instruction Service Provincial', 3, $delEst, null, false, false);
            $prStep3->setStandardDurationHours(72);

            $prStep4 = new WorkflowStep($wfProv, 'DIRECTION_VALIDATION', 'Validation Chef de Service Provincial', 4, $delEst, null, false, false);
            $prStep4->setStandardDurationHours(48);

            $prStep5 = new WorkflowStep($wfProv, 'SG_PROVINCIAL_VALIDATION', 'Validation Finale Délégué Provincial', 5, $delEst, null, false, false);
            $prStep5->setStandardDurationHours(48);

            $prStep6 = new WorkflowStep($wfProv, 'ARCHIVED', 'Clôture & Archivage Provincial', 6, $delEst, null, false, true);

            new WorkflowTransition($wfProv, $prStep1, $prStep2, WorkflowAction::Submit, 'Soumettre au Délégué Provincial', null, 'DOSSIER_FORWARD');
            new WorkflowTransition($wfProv, $prStep1, $prStep2, WorkflowAction::Forward, 'Transmettre au Délégué Provincial', null, 'DOSSIER_FORWARD');
            new WorkflowTransition($wfProv, $prStep2, $prStep2, WorkflowAction::Annotate, 'Annoter le dossier provincial', null, 'DOSSIER_ANNOTATE', true);
            new WorkflowTransition($wfProv, $prStep2, $prStep3, WorkflowAction::Orient, 'Orienter vers le service provincial', null, 'DOSSIER_FORWARD');
            new WorkflowTransition($wfProv, $prStep2, $prStep3, WorkflowAction::Assign, 'Affecter à l\'agent provincial', null, 'DOSSIER_ASSIGN');
            new WorkflowTransition($wfProv, $prStep2, $prStep3, WorkflowAction::Forward, 'Transmettre pour instruction', null, 'DOSSIER_FORWARD');
            new WorkflowTransition($wfProv, $prStep3, $prStep3, WorkflowAction::TakeInCharge, 'Prendre en charge le dossier', null, 'DOSSIER_TREAT');
            new WorkflowTransition($wfProv, $prStep3, $prStep4, WorkflowAction::Process, 'Soumettre le rapport d\'instruction', null, 'DOSSIER_TREAT');
            new WorkflowTransition($wfProv, $prStep3, $prStep4, WorkflowAction::Submit, 'Transmettre pour validation service', null, 'DOSSIER_TREAT');
            new WorkflowTransition($wfProv, $prStep4, $prStep3, WorkflowAction::Return, 'Retourner pour complément d\'instruction', null, 'DOSSIER_FORWARD', true, true);
            new WorkflowTransition($wfProv, $prStep4, $prStep3, WorkflowAction::Reject, 'Rejeter les conclusions', null, 'DOSSIER_VALIDATE', true, true);
            new WorkflowTransition($wfProv, $prStep4, $prStep5, WorkflowAction::Validate, 'Valider et soumettre au Délégué Provincial', null, 'DOSSIER_VALIDATE');
            new WorkflowTransition($wfProv, $prStep5, $prStep4, WorkflowAction::Return, 'Retourner au service provincial', null, 'DOSSIER_FORWARD', true, true);
            new WorkflowTransition($wfProv, $prStep5, $prStep6, WorkflowAction::Validate, 'Signer et clôturer au niveau provincial', null, 'DOSSIER_VALIDATE');
            new WorkflowTransition($wfProv, $prStep5, $prStep6, WorkflowAction::Release, 'Libérer et archiver le dossier provincial', null, 'DOSSIER_CLOSE');
            new WorkflowTransition($wfProv, $prStep5, $prStep6, WorkflowAction::Close, 'Clôturer le dossier provincial', null, 'DOSSIER_CLOSE');
        }

        // 7. Comptes administrateurs et utilisateurs de test / bootstrap
        $adminMail = $this->adminEmail ? trim($this->adminEmail) : 'admin@mci.local';
        $adminPass = ($this->adminPassword && trim($this->adminPassword) !== '') ? trim($this->adminPassword) : 'AdminDevSecret2026!';

        $defaultUsers = [
            [
                'username' => 'admin',
                'email' => $adminMail,
                'firstName' => 'Administrateur',
                'lastName' => 'MCI',
                'phone' => '+241 01 00 00 00',
                'unit' => $ministere,
                'role' => $roles['ADMINISTRATEUR'],
                'password' => $adminPass,
            ],
            [
                'username' => 'user_test',
                'email' => 'user.test@mci.gov',
                'firstName' => 'Jean',
                'lastName' => 'KOUASSI',
                'phone' => '+225 07 01 02 03 04',
                'unit' => $dirCommerceInterieur,
                'role' => $roles['AGENT_TRAITEMENT'],
                'password' => 'Password123!',
            ],
            [
                'username' => 'admin_test',
                'email' => 'admin.test@mci.gov',
                'firstName' => 'Administrateur',
                'lastName' => 'TEST',
                'phone' => '+225 07 00 00 00 01',
                'unit' => $dirCommerceInterieur,
                'role' => $roles['ADMINISTRATEUR'],
                'password' => 'Password123!',
            ],
        ];

        foreach ($defaultUsers as $uData) {
            $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => strtolower($uData['email'])])
                ?? $this->entityManager->getRepository(User::class)->findOneBy(['username' => $uData['username']]);

            if (!$user) {
                $user = new User($uData['email'], $uData['firstName'], $uData['lastName']);
                $this->entityManager->persist($user);
                $created = true;
            } else {
                $user->setEmail($uData['email']);
                $user->setFirstName($uData['firstName']);
                $user->setLastName($uData['lastName']);
                $created = false;
            }

            $user->setUsername($uData['username']);
            $user->setPhone($uData['phone']);
            $user->setOrganizationUnit($uData['unit']);
            $user->addAccessRole($uData['role']);
            $user->activate();
            $user->setPassword($this->passwordHasher->hashPassword($user, $uData['password']));

            if ($created) {
                $io->success(sprintf('Compte créé : %s (%s)', $uData['username'], $uData['email']));
            } else {
                $io->note(sprintf('Compte mis à jour : %s (%s)', $uData['username'], $uData['email']));
            }
        }

        $this->entityManager->flush();

        $io->success('Référentiels et données initiales MCI e-Circuit installés avec succès.');

        return Command::SUCCESS;
    }

    private function upsertPermission(string $code, string $label, string $module): AccessPermission
    {
        $item = $this->entityManager->getRepository(AccessPermission::class)->findOneBy(['code' => $code]);
        if (!$item) {
            $item = new AccessPermission($code, $label, $module);
            $this->entityManager->persist($item);
        } else {
            $item->setLabel($label);
            $item->setModule($module);
        }
        return $item;
    }

    private function upsertRole(string $code, string $label, string $description): AccessRole
    {
        $item = $this->entityManager->getRepository(AccessRole::class)->findOneBy(['code' => $code]);
        if (!$item) {
            $item = new AccessRole($code, $label, $description);
            $this->entityManager->persist($item);
        } else {
            $item->setLabel($label);
            $item->setDescription($description);
        }
        return $item;
    }

    private function upsertUnit(string $code, string $name, StructureType $type, ?OrganizationUnit $parent, int $displayOrder): OrganizationUnit
    {
        $item = $this->entityManager->getRepository(OrganizationUnit::class)->findOneBy(['code' => $code]);
        if (!$item) {
            $item = new OrganizationUnit($code, $name, $type);
            $item->setParent($parent);
            $item->setDisplayOrder($displayOrder);
            $this->entityManager->persist($item);
        } else {
            $item->setName($name);
            $item->setType($type);
            $item->setParent($parent);
            $item->setDisplayOrder($displayOrder);
        }
        return $item;
    }

    /**
     * @param array<string, array{0: string, 1: ?string, 2: ?string, 3: int}> $items
     */
    private function upsertReferences(ReferenceCategory $category, array $items): void
    {
        foreach ($items as $code => [$label, $desc, $color, $order]) {
            $item = $this->entityManager->getRepository(ReferenceItem::class)->findOneBy([
                'category' => $category,
                'code' => $code,
            ]);
            if (!$item) {
                $item = new ReferenceItem($category, $code, $label, $desc, $order);
                $item->setUiColor($color);
                $this->entityManager->persist($item);
            } else {
                $item->setLabel($label);
                $item->setDescription($desc);
                $item->setUiColor($color);
                $item->setDisplayOrder($order);
            }
        }
    }
}
