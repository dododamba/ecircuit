<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module 10 — Audit & Journalisation de Sécurité :
 * Enrichissement de la table audit_logs (action, entity_type, entity_id, result, route, http_method, status_code, request_id)
 * et création des index de performance pour la recherche multicritère.
 */
final class Version20261030091000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enrichit la table audit_logs pour le Module 10 (Audit & Journalisation de Sécurité) et ajoute les index de recherche.';
    }

    public function up(Schema $schema): void
    {
        $intType = $this->connection->getDatabasePlatform() instanceof SqlitePlatform ? 'INTEGER' : 'INT';

        $this->addSql("ALTER TABLE audit_logs ADD COLUMN action VARCHAR(100) DEFAULT '' NOT NULL");
        $this->addSql('ALTER TABLE audit_logs ADD COLUMN entity_type VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE audit_logs ADD COLUMN entity_id VARCHAR(64) DEFAULT NULL');
        $this->addSql("ALTER TABLE audit_logs ADD COLUMN result VARCHAR(20) DEFAULT 'SUCCESS' NOT NULL");
        $this->addSql('ALTER TABLE audit_logs ADD COLUMN route VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE audit_logs ADD COLUMN http_method VARCHAR(10) DEFAULT NULL');
        $this->addSql("ALTER TABLE audit_logs ADD COLUMN status_code {$intType} DEFAULT NULL");
        $this->addSql('ALTER TABLE audit_logs ADD COLUMN request_id VARCHAR(64) DEFAULT NULL');

        $this->addSql("UPDATE audit_logs SET action = event WHERE action = ''");
        $this->addSql("UPDATE audit_logs SET result = CASE WHEN success = 1 THEN 'SUCCESS' ELSE 'FAILURE' END");

        $this->addSql('CREATE INDEX idx_audit_logs_created_at ON audit_logs (created_at)');
        $this->addSql('CREATE INDEX idx_audit_logs_action ON audit_logs (action)');
        $this->addSql('CREATE INDEX idx_audit_logs_event ON audit_logs (event)');
        $this->addSql('CREATE INDEX idx_audit_logs_entity ON audit_logs (entity_type, entity_id)');
        $this->addSql('CREATE INDEX idx_audit_logs_result ON audit_logs (result)');
        if ($this->connection->getDatabasePlatform() instanceof SqlitePlatform) {
            $this->addSql('CREATE INDEX idx_audit_logs_user ON audit_logs (user_id)');
        }
        $this->addSql('CREATE INDEX idx_audit_logs_route ON audit_logs (route)');
    }

    public function down(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SqlitePlatform) {
            $this->addSql('DROP INDEX idx_audit_logs_created_at');
            $this->addSql('DROP INDEX idx_audit_logs_action');
            $this->addSql('DROP INDEX idx_audit_logs_event');
            $this->addSql('DROP INDEX idx_audit_logs_entity');
            $this->addSql('DROP INDEX idx_audit_logs_result');
            $this->addSql('DROP INDEX idx_audit_logs_user');
            $this->addSql('DROP INDEX idx_audit_logs_route');
            return;
        }

        $this->addSql('DROP INDEX idx_audit_logs_created_at ON audit_logs');
        $this->addSql('DROP INDEX idx_audit_logs_action ON audit_logs');
        $this->addSql('DROP INDEX idx_audit_logs_event ON audit_logs');
        $this->addSql('DROP INDEX idx_audit_logs_entity ON audit_logs');
        $this->addSql('DROP INDEX idx_audit_logs_result ON audit_logs');
        $this->addSql('DROP INDEX idx_audit_logs_route ON audit_logs');
        $this->addSql('ALTER TABLE audit_logs DROP COLUMN action, DROP COLUMN entity_type, DROP COLUMN entity_id, DROP COLUMN result, DROP COLUMN route, DROP COLUMN http_method, DROP COLUMN status_code, DROP COLUMN request_id');
    }
}
