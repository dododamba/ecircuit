<?php

namespace App\Tests\Performance;

use App\Entity\AccessRole;
use App\Entity\User;
use App\Enum\AccountStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PerformanceBenchmarkTest extends WebTestCase
{
    private function getOrCreateAdminUser(): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'admin_bench@mci.local']);
        if (!$user) {
            $user = new User('admin_bench@mci.local', 'Admin', 'Benchmark');
            $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
            $user->setPassword($hasher->hashPassword($user, 'Password123!'));
            $user->setStatus(AccountStatus::Active);

            $adminRole = $em->getRepository(AccessRole::class)->findOneBy(['code' => 'ADMINISTRATEUR']);
            if ($adminRole) {
                $user->addAccessRole($adminRole);
            }

            $em->persist($user);
            $em->flush();
        }

        return $user;
    }

    /**
     * Test S5.07 — Vérification des performances de réponse HTTP (< 2.0s) sur les routes clés
     */
    #[DataProvider('provideKeyRoutes')]
    public function testRouteResponseTimeIsUnderTwoSeconds(string $route, string $label): void
    {
        $client = static::createClient();
        $admin = $this->getOrCreateAdminUser();
        $client->loginUser($admin);

        $start = microtime(true);
        $client->request('GET', $route);
        $duration = microtime(true) - $start;

        self::assertResponseIsSuccessful(sprintf('La route %s (%s) doit répondre avec un code 200 OK.', $route, $label));
        self::assertLessThan(
            2.0,
            $duration,
            sprintf('La route %s (%s) doit répondre en moins de 2.0s (durée mesurée : %.3fs).', $route, $label, $duration)
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideKeyRoutes(): iterable
    {
        yield 'Dashboard Central' => ['/', 'Tableau de bord'];
        yield 'Registre Courrier Entrant' => ['/courrier/entrant', 'Courrier Entrant'];
        yield 'Registre Courrier Sortant' => ['/courrier/sortant', 'Courrier Sortant'];
        yield 'Liste des Dossiers' => ['/dossiers', 'Liste des dossiers'];
        yield 'Moteur de Recherche' => ['/recherche?q=MCI', 'Recherche multicritère'];
        yield 'Administration des Circuits' => ['/administration/circuits', 'Circuits administratifs'];
        yield 'Journal d Audit de Sécurité' => ['/administration/audit-logs', 'Audit logs'];
    }
}
