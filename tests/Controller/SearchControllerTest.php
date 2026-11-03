<?php

namespace App\Tests\Controller;

use App\Entity\AccessRole;
use App\Entity\Correspondence;
use App\Entity\DossierCase;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\CorrespondenceStatus;
use App\Enum\EntryChannel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SearchControllerTest extends WebTestCase
{
    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function getPasswordHasher(): UserPasswordHasherInterface
    {
        return self::getContainer()->get(UserPasswordHasherInterface::class);
    }

    private function loginAgentWithRole(KernelBrowser $client, string $roleCode, string $email): User
    {
        $em = $this->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $user = new User($email, 'Agent', $roleCode);
            $user->setPassword($this->getPasswordHasher()->hashPassword($user, 'PasswordSecret123!'));
            $user->setStatus(AccountStatus::Active);

            $role = $em->getRepository(AccessRole::class)->findOneBy(['code' => $roleCode]);
            if ($role) {
                $user->addAccessRole($role);
            }

            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);
        return $user;
    }

    public function testAnonymousAccessRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/recherche');
        self::assertResponseRedirects('/login');

        $client->request('GET', '/recherche/rapide?q=MCI-TEST');
        self::assertResponseRedirects('/login');
    }

    public function testQuickSearchRedirectsDirectlyToDossier(): void
    {
        $client = static::createClient();
        $this->loginAgentWithRole($client, 'ADMINISTRATEUR', 'admin_search@mci.local');

        $em = $this->getEntityManager();
        $ref = 'MCI-' . date('Y') . '-' . mt_rand(500000, 599999);
        $case = new DossierCase($ref, 'Dossier de recherche rapide test', EntryChannel::Cabinet, new \DateTimeImmutable());
        $case->setStatusCode('REGISTERED');
        $case->setPriorityCode('NORMALE');
        $em->persist($case);
        $em->flush();

        $client->request('GET', '/recherche/rapide', ['q' => $ref]);
        self::assertResponseRedirects('/dossiers/' . $case->getId());

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $ref);
    }

    public function testQuickSearchRedirectsDirectlyToCorrespondence(): void
    {
        $client = static::createClient();
        $this->loginAgentWithRole($client, 'ADMINISTRATEUR', 'admin_search2@mci.local');

        $em = $this->getEntityManager();
        $ref = 'MCI-' . date('Y') . '-' . mt_rand(600000, 699999);
        $corr = new Correspondence('Courrier recherche rapide', EntryChannel::SecretariatGeneral, new \DateTimeImmutable());
        $corr->assignReference($ref);
        $corr->setStatus(CorrespondenceStatus::Validated);
        $em->persist($corr);
        $em->flush();

        $client->request('GET', '/recherche/rapide', ['q' => $ref]);
        self::assertResponseRedirects('/courrier/' . $corr->getId());

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $ref);
    }

    public function testMultiCriteriaSearchPageRendersAndFilters(): void
    {
        $client = static::createClient();
        $this->loginAgentWithRole($client, 'ADMINISTRATEUR', 'admin_search3@mci.local');

        $client->request('GET', '/recherche');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Moteur de recherche ministériel');
        self::assertSelectorExists('input[name="q"]');
        self::assertSelectorExists('select[name="status"]');
        self::assertSelectorExists('select[name="priority"]');
        self::assertSelectorExists('select[name="channel"]');

        // Test filtrage avec mot clé
        $client->request('GET', '/recherche', ['q' => 'recherche', 'search_submitted' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('table');
    }

    public function testSearchExportCsv(): void
    {
        $client = static::createClient();
        $this->loginAgentWithRole($client, 'ADMINISTRATEUR', 'admin_search4@mci.local');

        $client->request('GET', '/recherche/export', ['q' => 'MCI']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/csv; charset=utf-8');
        
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Référence', $content);
        self::assertStringContainsString('Objet', $content);
    }
}
