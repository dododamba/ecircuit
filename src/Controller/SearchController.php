<?php

namespace App\Controller;

use App\Entity\Correspondence;
use App\Entity\DossierCase;
use App\Entity\OrganizationUnit;
use App\Entity\ReferenceItem;
use App\Entity\User;
use App\Enum\CorrespondenceStatus;
use App\Enum\EntryChannel;
use App\Enum\ReferenceCategory;
use App\Repository\CorrespondenceRepository;
use App\Repository\DossierCaseRepository;
use App\Service\AccessScopeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class SearchController extends AbstractController
{
    public function __construct(
        private readonly AccessScopeService $access,
        private readonly DossierCaseRepository $dossierRepository,
        private readonly CorrespondenceRepository $correspondenceRepository,
        private readonly EntityManagerInterface $entityManager
    ) {}

    private function user(): User
    {
        /** @var User $user */
        $user = $this->getUser();
        return $user;
    }

    /**
     * Recherche rapide (barre de navigation supérieure ou formulaire direct)
     * Si la référence correspond exactement à un dossier ou un courrier autorisé, redirection directe !
     */
    #[Route('/recherche/rapide', name: 'search_quick', methods: ['GET'])]
    public function quickSearch(Request $request): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        if ($query === '') {
            return $this->redirectToRoute('search_index');
        }

        $cleanRef = strtoupper($query);
        $user = $this->user();

        // 1. Recherche par référence exacte dans les dossiers
        $case = $this->dossierRepository->findOneBy(['reference' => $cleanRef]);
        if ($case !== null) {
            $this->access->assertDossierAccess($user, $case);
            $this->addFlash('info', sprintf('Dossier trouvé pour la référence %s.', $cleanRef));
            return $this->redirectToRoute('dossier_show', ['id' => $case->getId()]);
        }

        // 2. Recherche par référence exacte dans les courriers
        $corr = $this->correspondenceRepository->findOneBy(['reference' => $cleanRef]);
        if ($corr !== null) {
            $this->access->assertCorrespondenceAccess($user, $corr);
            $this->addFlash('info', sprintf('Courrier trouvé pour la référence %s.', $cleanRef));
            return $this->redirectToRoute('courrier_show', ['id' => $corr->getId()]);
        }

        // 3. Si aucun match exact, redirection vers la page de recherche multi-critères
        return $this->redirectToRoute('search_index', ['q' => $query]);
    }

    /**
     * Moteur de recherche globale et multi-critères (Module 6 — S4.01 & S4.02)
     */
    #[Route('/recherche', name: 'search_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->user();
        $q = trim((string) $request->query->get('q', ''));
        $type = $request->query->get('type', 'all');
        $status = $request->query->get('status');
        $priority = $request->query->get('priority');
        $channel = $request->query->get('channel');
        $structureId = $request->query->getInt('structure_id') ?: null;
        $assigneeId = $request->query->getInt('assignee_id') ?: null;
        $caseTypeId = $request->query->getInt('case_type_id') ?: null;
        $documentType = trim((string) $request->query->get('document_type', '')) ?: null;
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');
        $createdFrom = $request->query->get('created_from');
        $createdTo = $request->query->get('created_to');
        $overdueOnly = $request->query->getBoolean('overdue_only');

        $page = max(1, $request->query->getInt('page', 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $filters = [
            'search' => $q !== '' ? $q : null,
            'status' => $status ?: null,
            'priority' => $priority ?: null,
            'channel' => $channel ?: null,
            'structure_id' => $structureId,
            'assignee_id' => $assigneeId,
            'case_type_id' => $caseTypeId,
            'document_type' => $documentType,
            'date_from' => $dateFrom ?: null,
            'date_to' => $dateTo ?: null,
            'created_from' => $createdFrom ?: null,
            'created_to' => $createdTo ?: null,
            'overdue_only' => $overdueOnly,
            'current_user' => $user,
        ];

        $hasSearch = (
            $q !== '' || $status || $priority || $channel || $structureId
            || $assigneeId || $caseTypeId || $documentType
            || $dateFrom || $dateTo || $createdFrom || $createdTo
            || $overdueOnly
        );

        $dossiers = [];
        $dossiersCount = 0;
        $correspondences = [];
        $correspondencesCount = 0;

        if ($hasSearch || $request->query->has('search_submitted')) {
            if ($type === 'all' || $type === 'dossier') {
                $dossiers = $this->dossierRepository->findByFilters($filters, $type === 'dossier' ? $limit : 10, $type === 'dossier' ? $offset : 0);
                $dossiersCount = $this->dossierRepository->countByFilters($filters);
            }

            if ($type === 'all' || $type === 'courrier') {
                $corrFilters = [
                    'search' => $filters['search'],
                    'channel' => $filters['channel'],
                    'priority' => $filters['priority'],
                    'status' => $filters['status'],
                    'document_type' => $filters['document_type'],
                    'dateFrom' => $filters['date_from'],
                    'dateTo' => $filters['date_to'],
                    'current_user' => $user,
                ];
                $correspondences = $this->correspondenceRepository->findByFilters($corrFilters, $type === 'courrier' ? $limit : 10, $type === 'courrier' ? $offset : 0);
                $correspondencesCount = $this->correspondenceRepository->countByFilters($corrFilters);
            }
        }

        $allStructures = $this->entityManager->getRepository(OrganizationUnit::class)->findBy(['active' => true], ['name' => 'ASC']);
        $structures = $this->access->filterAccessibleStructures($user, $allStructures);
        if (empty($structures)) {
            $structures = $allStructures;
        }

        $agents = $this->entityManager->getRepository(User::class)->findBy([], ['lastName' => 'ASC', 'firstName' => 'ASC']);

        $statuses = $this->entityManager->getRepository(ReferenceItem::class)->findBy([
            'category' => ReferenceCategory::DossierStatus,
            'active' => true,
        ], ['displayOrder' => 'ASC']);

        $priorities = $this->entityManager->getRepository(ReferenceItem::class)->findBy([
            'category' => ReferenceCategory::Priority,
            'active' => true,
        ], ['displayOrder' => 'ASC']);

        $documentTypes = $this->entityManager->getRepository(ReferenceItem::class)->findBy([
            'category' => ReferenceCategory::DocumentType,
            'active' => true,
        ], ['displayOrder' => 'ASC']);

        $caseTypes = $this->entityManager->getRepository(ReferenceItem::class)->findBy([
            'category' => ReferenceCategory::RequestType,
            'active' => true,
        ], ['displayOrder' => 'ASC']);

        $totalResults = $dossiersCount + $correspondencesCount;
        $totalPages = $type === 'all' ? 1 : max(1, (int) ceil(($type === 'dossier' ? $dossiersCount : $correspondencesCount) / $limit));

        return $this->render('search/index.html.twig', [
            'q' => $q,
            'type' => $type,
            'filters' => $filters,
            'dossiers' => $dossiers,
            'dossiers_count' => $dossiersCount,
            'correspondences' => $correspondences,
            'correspondences_count' => $correspondencesCount,
            'total_results' => $totalResults,
            'has_search' => $hasSearch,
            'structures' => $structures,
            'agents' => $agents,
            'statuses' => $statuses,
            'priorities' => $priorities,
            'document_types' => $documentTypes,
            'case_types' => $caseTypes,
            'channels' => EntryChannel::cases(),
            'current_page' => $page,
            'total_pages' => $totalPages,
        ]);
    }

    /**
     * Export CSV des résultats de recherche (soumis au périmètre organisationnel)
     */
    #[Route('/recherche/export', name: 'search_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $user = $this->user();
        $q = trim((string) $request->query->get('q', ''));
        $status = $request->query->get('status');
        $priority = $request->query->get('priority');
        $channel = $request->query->get('channel');
        $structureId = $request->query->getInt('structure_id') ?: null;
        $assigneeId = $request->query->getInt('assignee_id') ?: null;
        $caseTypeId = $request->query->getInt('case_type_id') ?: null;
        $documentType = trim((string) $request->query->get('document_type', '')) ?: null;
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');
        $createdFrom = $request->query->get('created_from');
        $createdTo = $request->query->get('created_to');

        $filters = [
            'search' => $q !== '' ? $q : null,
            'status' => $status ?: null,
            'priority' => $priority ?: null,
            'channel' => $channel ?: null,
            'structure_id' => $structureId,
            'assignee_id' => $assigneeId,
            'case_type_id' => $caseTypeId,
            'document_type' => $documentType,
            'date_from' => $dateFrom ?: null,
            'date_to' => $dateTo ?: null,
            'created_from' => $createdFrom ?: null,
            'created_to' => $createdTo ?: null,
            'current_user' => $user,
        ];

        $dossiers = $this->dossierRepository->findByFilters($filters, 500, 0);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Impossible d\'initialiser le flux d\'export.');
        }

        // UTF-8 BOM pour compatibilité Excel
        fputs($handle, "\xEF\xBB\xBF");

        // Entêtes CSV
        fputcsv($handle, [
            'Référence',
            'Objet',
            'Canal d\'entrée',
            'Date de réception',
            'Structure actuelle',
            'Responsable actuel',
            'Statut',
            'Priorité',
            'Échéance',
        ], ';', '"', "\\");

        foreach ($dossiers as $case) {
            fputcsv($handle, [
                $case->getReference(),
                $case->getSubject(),
                $case->getEntryChannel()->label(),
                $case->getReceivedAt()->format('d/m/Y'),
                $case->getCurrentStructure()?->getName() ?? 'Non définie',
                $case->getCurrentAssignee()?->getFullName() ?? 'Non affecté',
                $case->getStatusCode(),
                $case->getPriorityCode(),
                $case->getDeadline()?->format('d/m/Y') ?? '-',
            ], ';', '"', "\\");
        }

        rewind($handle);
        $csvContent = stream_get_contents($handle);
        fclose($handle);

        $filename = sprintf('mci_ecircuit_export_%s.csv', (new \DateTimeImmutable())->format('Ymd_His'));
        $response = new Response($csvContent ?: '');
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }
}
