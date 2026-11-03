# MCI e-Circuit

**Plateforme institutionnelle de circulation, de traitement et de suivi
des dossiers administratifs**\
**Ministère du Commerce et de l'Industrie --- République de Côte
d'Ivoire**

[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Symfony](https://img.shields.io/badge/Symfony-8.1-000000?logo=symfony&logoColor=white)](https://symfony.com/)
[![Database](https://img.shields.io/badge/Database-MySQL%208%20%2F%20MariaDB-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Status](https://img.shields.io/badge/MVP-100%25%20achev%C3%A9-success)](#bilan-de-finalisation-du-mvp)

MCI e-Circuit est une application de dématérialisation du circuit
administratif ministériel. Elle permet d'enregistrer les courriers
entrants et sortants, de constituer et suivre les dossiers, d'orchestrer
leur traitement à travers des circuits administratifs configurables, de
gérer les échéances et de fournir des outils de pilotage adaptés aux
responsabilités de chaque profil.

> **État documenté au 30 octobre 2026 :** le rapport de finalisation et
> la matrice de conformité déclarent les 53 fonctionnalités du MVP
> achevées, avec 94 tests automatisés et 903 assertions validées. La
> plateforme est annoncée comme prête pour la recette ministérielle et
> la préparation du déploiement.

------------------------------------------------------------------------

## Sommaire

-   [Fonctionnalités principales](#fonctionnalités-principales)
-   [Architecture et technologies](#architecture-et-technologies)
-   [Sécurité et cloisonnement
    organisationnel](#sécurité-et-cloisonnement-organisationnel)
-   [Courrier et dossiers](#courrier-et-dossiers)
-   [Circuits administratifs et
    workflow](#circuits-administratifs-et-workflow)
-   [Échéances, notifications et
    escalades](#échéances-notifications-et-escalades)
-   [Recherche et tableaux de bord](#recherche-et-tableaux-de-bord)
-   [Traçabilité métier et audit de
    sécurité](#traçabilité-métier-et-audit-de-sécurité)
-   [Base de données](#base-de-données)
-   [Installation et configuration](#installation-et-configuration)
-   [Commandes d'exploitation](#commandes-dexploitation)
-   [Tests et qualité](#tests-et-qualité)
-   [Structure indicative du projet](#structure-indicative-du-projet)
-   [Bilan de finalisation du MVP](#bilan-de-finalisation-du-mvp)
-   [Préparation de la recette et du
    déploiement](#préparation-de-la-recette-et-du-déploiement)
-   [Documentation complémentaire](#documentation-complémentaire)

------------------------------------------------------------------------

## Fonctionnalités principales

### 1. Gestion des identités et des organisations

-   Authentification par formulaire et gestion des sessions.
-   Gestion des utilisateurs, rôles et permissions selon un modèle RBAC.
-   Gestion hiérarchique des structures ministérielles.
-   Gestion des référentiels, types et statuts.
-   Administration à travers une interface basée sur Metronic.
-   Cloisonnement des données selon le périmètre organisationnel de
    l'utilisateur.
-   Protection des formulaires et des opérations sensibles par CSRF.

### 2. Gestion du courrier entrant

-   Enregistrement des courriers reçus au Cabinet ou au Secrétariat
    Général.
-   Attribution d'une référence ministérielle au format
    `MCI-YYYY-NNNNNN`.
-   Gestion des expéditeurs et des pièces jointes.
-   Génération de la fiche suiveuse et du récépissé de dépôt.
-   Registre général du courrier entrant.
-   Rattachement du courrier à un dossier.

### 3. Gestion du courrier sortant

Le courrier sortant dispose d'un cycle de vie dédié :

-   Création et préparation du courrier.
-   Génération atomique de la référence `MCI-OUT-YYYY-NNNNNN`.
-   Identification du destinataire et de son organisation.
-   Identification du mode d'expédition et de la structure émettrice.
-   Téléversement du document officiel signé.
-   Rattachement au dossier d'origine.
-   Validation, préparation à l'expédition et enregistrement de
    l'expédition.

Cycle de statuts documenté :

`DRAFT` → `PENDING_VALIDATION` → `VALIDATED` → `READY_TO_SEND` → `SENT`

Le statut `CANCELLED` est également pris en charge.

### 4. Gestion des dossiers

-   Création et consultation des dossiers.
-   Affectation à une structure ou à un agent.
-   Prise en charge, transmission et retour motivé.
-   Validation hiérarchique et visas.
-   Consultation de l'historique métier.
-   Ajout de notes et de documents.
-   Clôture, sortie de circuit et archivage.
-   Gestion des échéances avec justification obligatoire en cas de
    modification.

### 5. Workflow et circuits administratifs

-   Moteur de workflow avec étapes, transitions, tâches et instances.
-   Circuit standard Cabinet.
-   Circuit standard direct du Secrétariat Général
    (`WORKFLOW_SG_STANDARD`) en 11 étapes.
-   Circuit des Délégations Provinciales (`WORKFLOW_PROVINCIAL`) en 6
    étapes.
-   Sélection automatique du circuit selon le canal d'entrée ou la
    structure concernée.
-   Synchronisation du catalogue des circuits administratifs avec le
    moteur de workflow.
-   Administration des circuits, des étapes, des acteurs et des délais.
-   Mise à jour non destructive des circuits afin de préserver les
    instances en cours.

### 6. Notifications, échéances et escalades

-   Notifications internes dans l'application.
-   Marquage des notifications comme lues.
-   Détection des tâches et dossiers dont l'échéance est dépassée.
-   Alerte de l'agent assignataire ou des membres de la structure
    détentrice.
-   Escalade vers le supérieur hiérarchique.
-   Prévention des notifications en doublon par mécanisme d'idempotence
    journalier.
-   Historisation de l'escalade dans le workflow, la timeline métier et
    le journal d'audit.

### 7. Recherche et pilotage

La recherche multicritère prend notamment en charge :

-   Mot-clé global : référence, objet, expéditeur ou organisation.
-   Statut et priorité du dossier.
-   Canal d'origine et structure détentrice.
-   Agent assigné et type de dossier.
-   Nature du document rattaché.
-   Périodes de réception et d'enregistrement.
-   Filtre des dossiers en retard.
-   Export CSV des résultats.

Les tableaux de bord sont adaptés aux profils :

-   **Cabinet et Ministre :** dossiers en attente de signature et
    dossiers à libérer.
-   **Secrétariat Général :** dossiers à orienter et pilotage sectoriel
    par Direction Générale.
-   **Directions opérationnelles :** indicateurs et dossiers limités au
    périmètre hiérarchique autorisé.
-   **Pilotage :** délais moyens de traitement, taux de respect des
    échéances et durée moyenne par étape du workflow.

------------------------------------------------------------------------

## Architecture et technologies

  Composant                    Technologie / version documentée
  ---------------------------- ----------------------------------
  Langage backend              PHP 8.4.1
  Framework                    Symfony 8.1
  ORM                          Doctrine ORM 3.7
  Couche d'accès aux données   Doctrine DBAL
  Migrations                   Doctrine Migrations Bundle 4.0
  Base de données cible        MySQL 8.0+ / MariaDB 10.4+
  Interface serveur            Twig
  Kit d'interface              Metronic
  Tests                        PHPUnit 13.3.4
  Encodage SQL                 `utf8mb4`
  Moteur de stockage           InnoDB

Les entités Doctrine utilisent le mapping par attributs PHP dans
`src/Entity`.

### Principaux domaines applicatifs

-   **IAM et sécurité :** utilisateurs, rôles, permissions, sessions et
    contrôle d'accès.
-   **Organisation :** structures, hiérarchie et périmètres de
    visibilité.
-   **Courrier :** expéditeurs, courriers entrants et sortants,
    séquences de références.
-   **Dossiers :** dossiers administratifs, événements, transmissions et
    documents.
-   **Workflow :** circuits, étapes, transitions, instances, tâches et
    historique.
-   **Notifications :** alertes internes et suivi de lecture.
-   **Audit :** journalisation technique et événements de sécurité.

------------------------------------------------------------------------

## Sécurité et cloisonnement organisationnel

Le contrôle d'accès repose sur les rôles, les permissions et le
périmètre structurel de l'utilisateur.

Le service `AccessScopeService` prend notamment en charge :

-   La distinction entre les profils à visibilité transversale et les
    profils rattachés à une structure.
-   La résolution récursive des structures filles.
-   Le contrôle d'accès aux dossiers, courriers et documents.
-   La prise en compte de l'assignation directe.
-   La prise en compte de la structure actuellement détentrice.
-   La prise en compte de la participation historique légitime à un
    dossier.

Le `StructureScopeVoter` applique le contrôle de périmètre aux objets
organisationnels et métiers concernés.

Les contrôles sont appliqués dans les repositories, contrôleurs, routes
de recherche et opérations de téléchargement. Une tentative d'accès non
autorisée peut être refusée avec une réponse HTTP `403` et consignée
dans le journal d'audit.

**Principe :** un agent ou un directeur ne doit accéder qu'aux
informations autorisées par ses habilitations et son périmètre
organisationnel, sous réserve des rôles transversaux explicitement
prévus.

------------------------------------------------------------------------

## Courrier et dossiers

### Références

  Objet              Format
  ------------------ -----------------------
  Courrier entrant   `MCI-YYYY-NNNNNN`
  Courrier sortant   `MCI-OUT-YYYY-NNNNNN`

La génération des références sortantes utilise un mécanisme
transactionnel avec verrouillage pessimiste afin de sécuriser
l'incrémentation du compteur.

### Routes du courrier sortant

  ---------------------------------------------------------------------------------
  Méthode                 Route                             Fonction
  ----------------------- --------------------------------- -----------------------
  `GET`                   `/courrier/sortant`               Registre du courrier
                                                            sortant

  `GET`, `POST`           `/courrier/sortant/nouveau`       Préparation d'un
                                                            courrier

  `GET`                   `/courrier/sortant/{id}`          Consultation détaillée

  `POST`                  `/courrier/sortant/{id}/status`   Transition de statut
  ---------------------------------------------------------------------------------

Les opérations de transition sont protégées par CSRF et par le contrôle
du périmètre d'accès.

------------------------------------------------------------------------

## Circuits administratifs et workflow

### Circuit Secrétariat Général

Le circuit `WORKFLOW_SG_STANDARD` est constitué des étapes suivantes :

1.  `SG_RECEPTION` --- Réception Secrétariat Général.
2.  `SG_REVIEW` --- Orientation Secrétaire Général.
3.  `DG_REVIEW` --- Orientation Direction Générale.
4.  `DIRECTION_PROCESSING` --- Affectation Direction.
5.  `AGENT_PROCESSING` --- Instruction opérationnelle.
6.  `DIRECTION_VALIDATION` --- Validation Direction.
7.  `DG_VALIDATION` --- Validation Direction Générale.
8.  `SG_VALIDATION` --- Validation Secrétaire Général.
9.  `MINISTRE_SIGNATURE` --- Signature Ministre.
10. `SG_RELEASE` --- Sortie et expédition SG.
11. `ARCHIVED` --- Clôture et archives ministérielles.

La sélection de ce circuit est automatique lorsque le canal d'entrée
correspond au Secrétariat Général.

### Circuit des Délégations Provinciales

Le circuit `WORKFLOW_PROVINCIAL` comporte six étapes :

1.  `PROVINCIAL_RECEPTION` --- Réception Délégation Provinciale.
2.  `SG_PROVINCIAL_REVIEW` --- Orientation Délégué Provincial.
3.  `DIRECTION_PROCESSING` --- Instruction Service Provincial.
4.  `DIRECTION_VALIDATION` --- Validation Chef de Service Provincial.
5.  `SG_PROVINCIAL_VALIDATION` --- Validation finale Délégué Provincial.
6.  `ARCHIVED` --- Clôture et archivage provincial.

Le cloisonnement géographique interdit notamment qu'un dossier d'une
délégation soit pris en charge par une autre délégation non autorisée.

### Synchronisation du back-office

`CircuitWorkflowSyncService` assure la synchronisation entre le
catalogue des circuits administratifs et le moteur de workflow. La
synchronisation crée les workflows et leurs étapes/transitions
lorsqu'ils n'existent pas, ou met à jour les définitions sans perturber
les instances actives.

------------------------------------------------------------------------

## Échéances, notifications et escalades

La commande `app:workflow:check-deadlines` évalue les échéances des
tâches actives.

Options documentées :

  -----------------------------------------------------------------------
  Option                              Description
  ----------------------------------- -----------------------------------
  `--dry-run`                         Simule le traitement sans écrire en
                                      base

  `--force`                           Autorise une nouvelle escalade même
                                      si une escalade existe déjà

  `--case-ref`                        Cible un dossier précis

  `--limit`                           Limite le nombre d'éléments traités
  -----------------------------------------------------------------------

Le traitement identifie les tâches en attente ou en cours dont
l'échéance effective est dépassée. Il peut notifier l'agent responsable
et escalader vers le supérieur hiérarchique.

Les événements sont tracés dans :

-   `WorkflowHistory` pour l'historique du moteur.
-   `CaseEvent` pour la timeline métier du dossier.
-   `AuditLog` pour la traçabilité technique et de sécurité.

La modification d'une échéance nécessite une justification et est
refusée sur les dossiers clôturés ou archivés.

------------------------------------------------------------------------

## Recherche et tableaux de bord

### Filtres de recherche

Les filtres documentés comprennent notamment :

`status`, `priority`, `channel`, `structure_id`, `assignee_id`,
`case_type_id`, `document_type`, `date_from`, `date_to`, `created_from`,
`created_to`, `overdue_only`.

La recherche respecte le périmètre de visibilité de l'utilisateur.

### Indicateurs

Les indicateurs documentés comprennent :

-   Nombre total de dossiers.
-   Dossiers à traiter et dossiers en cours.
-   Dossiers clôturés et dossiers en retard.
-   Répartition sectorielle par Direction Générale.
-   Taux de charge.
-   Délai moyen de résolution.
-   Taux de respect des délais réglementaires.
-   Durée moyenne effective par étape du workflow.

------------------------------------------------------------------------

## Traçabilité métier et audit de sécurité

MCI e-Circuit distingue deux mécanismes complémentaires.

  -----------------------------------------------------------------------
  Timeline métier (`CaseEvent`)       Audit technique (`AuditLog`)
  ----------------------------------- -----------------------------------
  Destinée aux agents et responsables Destiné aux administrateurs et
  métiers                             auditeurs

  Lisible et orientée vers le         Technique, détaillé et orienté
  traitement du dossier               sécurité

  Affectation, transmission, visa,    IP, route, méthode HTTP, résultat,
  note, escalade, prolongation        refus d'accès et données techniques
  d'échéance                          
  -----------------------------------------------------------------------

Cette séparation évite de mélanger l'historique opérationnel d'un
dossier avec les traces techniques nécessaires à l'investigation et à
l'audit.

------------------------------------------------------------------------

## Base de données

La base de référence documentée est **MySQL 8.0+ / MariaDB 10.4+**.

Configuration attendue :

-   Charset : `utf8mb4`
-   Collation : `utf8mb4_unicode_ci`
-   Engine : `InnoDB`

Les migrations Doctrine ont été révisées pour être compatibles avec
MySQL/MariaDB. Le rapport de finalisation indique que trois migrations
ont été exécutées sur la base de référence, avec 99 requêtes SQL et sans
erreur. La commande `doctrine:schema:validate` a confirmé que le mapping
et le schéma sont synchronisés.

> La configuration locale réelle doit être fournie par l'environnement
> de déploiement. Ne versionnez pas de mot de passe ou de secret dans le
> dépôt.

------------------------------------------------------------------------

## Installation et configuration

Les rapports de finalisation identifient les prérequis suivants pour
l'environnement d'intégration :

-   PHP 8.4.
-   Extensions PHP : `pdo_mysql`, `intl`, `mbstring`, `zip` (ainsi que
    les extensions nécessaires au projet).
-   MySQL 8.0+ ou MariaDB 10.4+.
-   Composer.
-   Configuration SQL en `utf8mb4` avec collation `utf8mb4_unicode_ci`.

### Procédure de mise en place

Les commandes ci-dessous constituent une séquence Symfony/Doctrine
standard à adapter aux scripts réellement présents dans le dépôt.

``` bash
# 1. Récupérer le projet
git clone <URL_DU_DEPOT>
cd <REPERTOIRE_DU_PROJET>

# 2. Installer les dépendances PHP
composer install

# 3. Configurer l'environnement
# Renseigner DATABASE_URL dans .env.local
# Exemple :
# DATABASE_URL="mysql://USER:PASSWORD@127.0.0.1:3306/NOM_BASE?serverVersion=8.0.32&charset=utf8mb4"

# 4. Créer / préparer la base selon l'environnement
php bin/console doctrine:database:create

# 5. Appliquer les migrations
php bin/console doctrine:migrations:migrate --no-interaction

# 6. Charger les référentiels et données institutionnelles
php bin/console app:seed:mci

# 7. Vérifier le mapping et le schéma
php bin/console doctrine:schema:validate

# 8. Exécuter les tests
php bin/phpunit
```

**Important :** vérifiez les chemins et scripts disponibles dans le
dépôt avant d'exécuter ces commandes. Le rapport de livraison mentionne
la commande `app:seed:mci` et les migrations Doctrine, mais les
paramètres propres à chaque environnement (URL du dépôt, identifiants
SQL, stockage des documents, serveur web et secrets) doivent être
définis par l'équipe de déploiement.

------------------------------------------------------------------------

## Commandes d'exploitation

### Évaluation des échéances

``` bash
php bin/console app:workflow:check-deadlines --dry-run
php bin/console app:workflow:check-deadlines
```

Pour cibler un dossier ou limiter le volume, les options `--case-ref` et
`--limit` sont disponibles. L'option `--force` permet de forcer une
nouvelle escalade selon les règles de la commande.

### Planification cron

Le rapport final recommande une exécution quotidienne, par exemple à
06:00 :

``` cron
0 6 * * * cd /CHEMIN/DU/PROJET && php bin/console app:workflow:check-deadlines --env=prod
```

Adapter le chemin, l'utilisateur système, l'environnement et la
politique de journalisation à l'infrastructure cible.

------------------------------------------------------------------------

## Tests et qualité

### Résultats consolidés

  -----------------------------------------------------------------------
  Indicateur                       État initial                État final
  ------------------- ------------------------- -------------------------
  Fonctionnalités                       36 / 53               **53 / 53**
  conformes                                     

  Avancement global                     83,95 %                 **100 %**

  Tests PHPUnit                              74                    **94**

  Assertions                                751                   **903**

  Échecs / erreurs           0 à l'état initial                     **0**

  Schéma MySQL                Conflits de types       **Mapping valide et
                                                     schéma synchronisé**
  -----------------------------------------------------------------------

Le rapport final indique une durée d'exécution totale d'environ 22
secondes pour la suite de tests.

### Suites de tests recensées

  Suite                                    Tests   Assertions
  ------------------------------------- -------- ------------
  `AccessScopeServiceTest`                     3           29
  `AuditAndSecurityLogTest`                   10           71
  `CheckWorkflowDeadlinesCommandTest`          1           20
  `CourrierAndNumberingTest`                   8           94
  `DashboardAndSearchTest`                     5           29
  `DossierAndDocumentTest`                     9          101
  `NotificationTest`                           8           51
  `OrganizationAndReferenceTest`              15          198
  `PerformanceBenchmarkTest`                   7           14
  `SearchControllerTest`                       5           27
  `TimelineAndTraceabilityTest`                9          221
  `UiLayoutTest`                               4           54
  `WorkflowServiceTest`                       19           74
  **Total**                               **94**      **903**

### Benchmark

Le benchmark automatisé couvre sept routes stratégiques :

-   `/`
-   `/courrier/entrant`
-   `/courrier/sortant`
-   `/dossiers`
-   `/recherche?q=MCI`
-   `/administration/circuits`
-   `/administration/audit-logs`

Le rapport du lot de stabilisation annonce un temps cumulé inférieur à
0,9 seconde pour les sept routes, soit une moyenne inférieure à 130 ms
par route dans l'environnement de test mesuré. Ces résultats sont des
mesures de test et ne constituent pas une garantie de performance en
production, où la charge et l'infrastructure peuvent différer.

------------------------------------------------------------------------

## Structure indicative du projet

Les chemins recensés dans les rapports comprennent notamment :

``` text
app/
├── config/
│   └── packages/
│       └── doctrine.yaml
├── migrations/
├── src/
│   ├── Command/
│   │   ├── CheckWorkflowDeadlinesCommand.php
│   │   └── SeedMciCommand.php
│   ├── Controller/
│   │   ├── AdminController.php
│   │   ├── CourrierController.php
│   │   ├── DashboardController.php
│   │   ├── DossierController.php
│   │   ├── SearchController.php
│   │   └── WorkflowController.php
│   ├── Entity/
│   ├── Enum/
│   ├── EventSubscriber/
│   │   └── SecurityAuditSubscriber.php
│   ├── Repository/
│   ├── Security/
│   │   └── Voter/
│   │       └── StructureScopeVoter.php
│   └── Service/
│       ├── AccessScopeService.php
│       ├── CircuitWorkflowSyncService.php
│       ├── OutgoingMailService.php
│       └── ReferenceNumberGenerator.php
├── templates/
│   ├── admin/
│   ├── bundles/
│   │   └── TwigBundle/
│   │       └── Exception/
│   ├── courrier/
│   ├── dashboard/
│   ├── dossier/
│   └── partials/
├── tests/
│   ├── Command/
│   ├── Controller/
│   ├── Performance/
│   └── Service/
└── docs/
    └── GUIDE_UTILISATEUR_MCI.md
```

Cette arborescence est indicative et reprend les composants cités dans
les rapports. Elle ne remplace pas l'inspection du dépôt réel.

------------------------------------------------------------------------

## Bilan de finalisation du MVP

La matrice de conformité finale répartit les 53 fonctionnalités comme
suit :

  ------------------------------------------------------------------------
  Sprint           Périmètre              Fonctionnalités Statut
  ---------------- ---------------- --------------------- ----------------
  Sprint 1         Socle technique,               10 / 10 **100 %**
                   IAM et                                 
                   organisation                           

  Sprint 2         Courrier entrant               10 / 10 **100 %**
                   et sortant                             

  Sprint 3         Circuits                       12 / 12 **100 %**
                   administratifs                         
                   et workflow                            
                   engine                                 

  Sprint 4         Recherche,                     10 / 10 **100 %**
                   pilotage et                            
                   tableaux de bord                       

  Sprint 5         Sécurité, audit,               11 / 11 **100 %**
                   performance et                         
                   clôture                                

  **Total**        **MVP**                    **53 / 53** **100 %**
  ------------------------------------------------------------------------

### Principaux lots de finalisation

1.  **Sécurité et base de données :** cloisonnement inter-structures de
    bout en bout, contrôle des accès aux dossiers/courriers/documents et
    alignement MySQL.
2.  **Courrier sortant :** numérotation officielle, cycle de vie, pièces
    jointes, rattachement au dossier et interfaces dédiées.
3.  **Circuits et workflow :** circuit SG, circuit provincial et
    synchronisation du back-office avec le moteur.
4.  **Échéances :** modification motivée, détection des retards,
    notifications et escalades idempotentes.
5.  **Pilotage :** recherche multicritère et tableaux de bord par profil
    avec indicateurs réels.
6.  **Stabilisation :** pages d'erreur, benchmark automatisé et
    documentation technique et utilisateur.

------------------------------------------------------------------------

## Préparation de la recette et du déploiement

Les recommandations recensées dans le rapport final sont :

1.  Déployer d'abord sur un serveur d'intégration.
2.  Vérifier PHP 8.4 et les extensions `pdo_mysql`, `intl`, `mbstring`
    et `zip`.
3.  Valider le serveur MySQL avec `utf8mb4` et `utf8mb4_unicode_ci`.
4.  Appliquer les migrations et vérifier le schéma Doctrine.
5.  Planifier la commande `app:workflow:check-deadlines` (fréquence
    quotidienne recommandée dans le rapport).
6.  Préparer les comptes et scénarios de recette à partir des données
    institutionnelles créées par `app:seed:mci`.
7.  Faire exécuter les scénarios de recette par les profils concernés :
    Cabinet, Ministre, Secrétariat Général, Directions et agents.

La mention « prêt pour la recette ministérielle » correspond à la
conclusion du rapport de livraison. La mise en production doit rester
conditionnée à la recette, à la validation de l'infrastructure, à la
configuration des secrets et à la vérification des sauvegardes et
procédures d'exploitation.

------------------------------------------------------------------------

## Documentation complémentaire

Le rapport de finalisation référence les documents suivants :

-   `docs/GUIDE_UTILISATEUR_MCI.md` --- guide utilisateur officiel,
    annoncé en 10 chapitres.
-   Les rapports de finalisation des lots 1 à 6.
-   La matrice de conformité finale des 53 fonctionnalités.
-   Le rapport final d'exécution et de livraison du MVP.

------------------------------------------------------------------------

## Informations de référence

-   **Application :** MCI e-Circuit
-   **Organisation :** Ministère du Commerce et de l'Industrie
-   **Pays indiqué dans les rapports :** République de Côte d'Ivoire
-   **Date de l'état initial et de finalisation documentée :** 30
    octobre 2026
-   **Statut déclaré :** MVP achevé --- 53 fonctionnalités sur 53
