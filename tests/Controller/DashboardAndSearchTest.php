<?php

namespace App\Tests\Controller;

use App\Entity\AccessRole;
use App\Entity\DossierCase;
use App\Entity\OrganizationUnit;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\EntryChannel;
use App\Enum\StructureType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DashboardAndSearchTest extends WebTestCase
{
    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function getPasswordHasher(): UserPasswordHasherInterface
    {
        return self::getContainer()->get(UserPasswordHasherInterface::class);
    }

    private function getOrCreateUser(string $email, string $roleCode, ?string $structureCode = null): User
    {
        $em = $this->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $user = new User($email, 'Test', $roleCode);
            $user->setPassword($this->getPasswordHasher()->hashPassword($user, 'Password123!'));
            $user->setStatus(AccountStatus::Active);

            $role = $em->getRepository(AccessRole::class)->findOneBy(['code' => $roleCode]);
            if ($role) {
                $user->addAccessRole($role);
            }

            if ($structureCode !== null) {
                $unit = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => $structureCode]);
                if ($unit) {
                    $user->setOrganizationUnit($unit);
                }
            }

            $em->persist($user);
            $em->flush();
        }

        return $user;
    }

    public function testSearchMulticriteriaFilters(): void
    {
        $client = static::createClient();
        $user = $this->getOrCreateUser('search_tester@mci.local', 'ADMINISTRATEUR');
        $client->loginUser($user);

        // 1. Recherche avec filtres avancés (S4.02)
        $client->request('GET', '/recherche', [
            'search_submitted' => '1',
            'q' => 'MCI',
            'overdue_only' => '1',
            'type' => 'dossier',
        ]);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Moteur de recherche ministériel', $content);
        self::assertStringContainsString('Uniquement les dossiers en retard d\'échéance', $content);
        self::assertStringContainsString('Agent instructeur', $content);
        self::assertStringContainsString('Nature de pièce', $content);
        self::assertStringContainsString('Enregistré à partir du', $content);
    }

    public function testDashboardCabinetRole(): void
    {
        $client = static::createClient();
        $user = $this->getOrCreateUser('cabinet_dash@mci.local', 'AGENT_CABINET', 'CABINET_MINISTRE');
        $client->loginUser($user);

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        // Vérification des blocs S4.07
        self::assertStringContainsString('Espace Cabinet & Ministre', $content);
        self::assertStringContainsString('Pilotage Cabinet & Décisions Ministérielles (S4.07)', $content);
        self::assertStringContainsString('Dossiers à signer par le Ministre', $content);
        self::assertStringContainsString('Dossiers à libérer du Cabinet', $content);
        self::assertStringContainsString('Indicateurs de performance ministérielle & Délais réels (S4.10)', $content);
    }

    public function testDashboardSecrétariatGénéralRole(): void
    {
        $client = static::createClient();
        $user = $this->getOrCreateUser('sg_dash@mci.local', 'SECRETAIRE_GENERAL', 'SECRETARIAT_GENERAL');
        $client->loginUser($user);

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        // Vérification des blocs S4.08
        self::assertStringContainsString('Espace Secrétariat Général', $content);
        self::assertStringContainsString('Pilotage Transversal du Secrétariat Général (S4.08)', $content);
        self::assertStringContainsString('Dossiers à orienter', $content);
        self::assertStringContainsString('Répartition par Direction Générale', $content);
        self::assertStringContainsString('Indicateurs de performance ministérielle & Délais réels (S4.10)', $content);
    }

    public function testDashboardDirectionRoleInterStructureIsolation(): void
    {
        $client = static::createClient();
        $em = $this->getEntityManager();

        $dciUnit = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'DIRECTION_COMMERCE_INTERIEUR']);
        self::assertNotNull($dciUnit, 'La Direction du Commerce Intérieur doit exister');

        $user = $this->getOrCreateUser('dir_dash@mci.local', 'RESPONSABLE_DIRECTION', 'DIRECTION_COMMERCE_INTERIEUR');
        $client->loginUser($user);

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        // Vérification des éléments S4.09
        self::assertStringContainsString('Direction du Commerce Intérieur', $content);
        self::assertStringContainsString('(S4.09)', $content);
        // Ne doit pas voir les blocs réservés SG ou Cabinet
        self::assertStringNotContainsString('Pilotage Cabinet & Décisions Ministérielles (S4.07)', $content);
        self::assertStringNotContainsString('Pilotage Transversal du Secrétariat Général (S4.08)', $content);
    }

    public function testDashboardPerformanceKpis(): void
    {
        $client = static::createClient();
        $user = $this->getOrCreateUser('admin_kpi@mci.local', 'ADMINISTRATEUR');
        $client->loginUser($user);

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        // Vérification S4.10
        self::assertStringContainsString('Indicateurs de performance ministérielle & Délais réels (S4.10)', $content);
        self::assertStringContainsString('Taux dans les délais', $content);
        self::assertStringContainsString('Délai moyen global', $content);
        self::assertStringContainsString('Temps moyen d\'instruction par étape administrative', $content);
    }
}
