<?php

namespace App\Tests\Controller;

use App\Entity\AccessRole;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UiLayoutTest extends WebTestCase
{
    public function testLoginPageRendersWithMetronicTheme(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('link[href*="keenicons"]');
        self::assertSelectorExists('link[href*="styles.css"]');
        self::assertSelectorExists('link[href*="favicon.ico"]');
        self::assertSelectorTextContains('body', 'MCI');
        self::assertSelectorTextContains('body', 'Connexion sécurisée');
        self::assertSelectorExists('input#username');
        self::assertSelectorExists('input#password');
    }

    public function testForgotPasswordRendersWithMetronicTheme(): void
    {
        $client = static::createClient();
        $client->request('GET', '/password/forgot');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('link[href*="keenicons"]');
        self::assertSelectorExists('link[href*="styles.css"]');
        self::assertSelectorTextContains('body', 'Récupération d\'accès');
        self::assertSelectorExists('input#email');
    }

    public function testDashboardRendersWithMetronicAdminLayoutForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'admin@mci.local']);
        if (!$user) {
            $user = new User('admin@mci.local', 'Admin', 'MCI');
            $user->setPassword('hashed_pw');
            $role = new AccessRole('ADMINISTRATEUR', 'Administrateur');
            $em->persist($role);
            $user->addAccessRole($role);
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('div#sidebar');
        self::assertSelectorExists('header#header');
        self::assertSelectorExists('link[href*="keenicons"]');
        self::assertSelectorExists('link[href*="styles.css"]');
        self::assertSelectorTextContains('body', 'Tableau de bord');
        self::assertSelectorTextContains('body', 'Espace de pilotage');
        self::assertSelectorTextContains('body', 'À traiter');
        self::assertSelectorTextContains('body', 'En instruction');
        self::assertSelectorTextContains('body', 'Urgents / En retard');
        self::assertSelectorTextContains('body', 'Traités / Clôturés');
        self::assertSelectorTextContains('body', 'Gestion Courrier');
        self::assertSelectorTextContains('body', 'Gestion Dossiers');
    }

    public function testOutgoingNewPageRendersWithCardDesignSystem(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = $em->getRepository(User::class)->findOneBy(['email' => 'admin@mci.local']);
        if (!$user) {
            $user = new User('admin@mci.local', 'Admin', 'MCI');
            $user->setPassword('hashed_pw');
            $role = new AccessRole('ADMINISTRATEUR', 'Administrateur');
            $em->persist($role);
            $user->addAccessRole($role);
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);
        $client->request('GET', '/courrier/sortant/nouveau');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.kt-card.kt-card-form');
        self::assertSelectorExists('.kt-card-header');
        self::assertSelectorExists('.kt-card-title');
        self::assertSelectorExists('.kt-form-section');
        self::assertSelectorExists('.mci-dropzone');
        self::assertSelectorExists('input#subject');
        self::assertSelectorExists('input#recipient_name');
        self::assertSelectorExists('select#issuing_structure_id');
        self::assertSelectorExists('select#dispatch_method');
        self::assertSelectorExists('select#document_type_id');
        self::assertSelectorExists('select#priority_id');
        self::assertSelectorExists('select#status');
        self::assertSelectorExists('input#main_document');
        self::assertSelectorExists('textarea#observations');
    }
}
