<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration initiale Sprint 1 — Socle IAM, Organisation, Référentiels, Circuits et Audit de base.
 * Compatible MySQL / MariaDB (cible principale InnoDB utf8mb4) et SQLite (environnement de test).
 */
final class Version20260921135924 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Socle initial Sprint 1 (IAM, Structures organisationnelles, Référentiels, Circuits administratifs, Audit).';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SqlitePlatform) {
            $this->addSql('CREATE TABLE access_permissions (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, code VARCHAR(100) NOT NULL, label VARCHAR(160) NOT NULL, module VARCHAR(80) NOT NULL, active BOOLEAN DEFAULT 1 NOT NULL)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_B778414177153098 ON access_permissions (code)');
            $this->addSql('CREATE TABLE access_roles (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, code VARCHAR(100) NOT NULL, label VARCHAR(160) NOT NULL, description CLOB DEFAULT NULL)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_5FAB529477153098 ON access_roles (code)');
            $this->addSql('CREATE TABLE access_role_permissions (access_role_id INTEGER NOT NULL, access_permission_id INTEGER NOT NULL, PRIMARY KEY (access_role_id, access_permission_id), CONSTRAINT FK_E0E73EE1E26F8B9 FOREIGN KEY (access_role_id) REFERENCES access_roles (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_E0E73EE66D760EE FOREIGN KEY (access_permission_id) REFERENCES access_permissions (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX IDX_E0E73EE1E26F8B9 ON access_role_permissions (access_role_id)');
            $this->addSql('CREATE INDEX IDX_E0E73EE66D760EE ON access_role_permissions (access_permission_id)');
            $this->addSql('CREATE TABLE administrative_circuits (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, code VARCHAR(100) NOT NULL, label VARCHAR(180) NOT NULL, description CLOB DEFAULT NULL, active BOOLEAN DEFAULT 1 NOT NULL)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_8D0A9B5777153098 ON administrative_circuits (code)');
            $this->addSql('CREATE TABLE organization_units (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, code VARCHAR(60) NOT NULL, name VARCHAR(180) NOT NULL, type VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, active BOOLEAN DEFAULT 1 NOT NULL, display_order INTEGER DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, parent_id INTEGER DEFAULT NULL, CONSTRAINT FK_6737928E727ACA70 FOREIGN KEY (parent_id) REFERENCES organization_units (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_6737928E77153098 ON organization_units (code)');
            $this->addSql('CREATE INDEX IDX_6737928E727ACA70 ON organization_units (parent_id)');
            $this->addSql('CREATE TABLE administrative_circuit_steps (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, position INTEGER NOT NULL, label VARCHAR(120) DEFAULT NULL, circuit_id INTEGER NOT NULL, organization_unit_id INTEGER NOT NULL, CONSTRAINT FK_2784D991CF2182C8 FOREIGN KEY (circuit_id) REFERENCES administrative_circuits (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2784D991356FF84E FOREIGN KEY (organization_unit_id) REFERENCES organization_units (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE UNIQUE INDEX circuit_position_unique ON administrative_circuit_steps (circuit_id, position)');
            $this->addSql('CREATE INDEX IDX_2784D991CF2182C8 ON administrative_circuit_steps (circuit_id)');
            $this->addSql('CREATE INDEX IDX_2784D991356FF84E ON administrative_circuit_steps (organization_unit_id)');
            $this->addSql('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, phone VARCHAR(40) DEFAULT NULL, username VARCHAR(100) DEFAULT NULL, password VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, last_login_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, disabled_at DATETIME DEFAULT NULL, organization_unit_id INTEGER DEFAULT NULL, CONSTRAINT FK_1483A5E9356FF84E FOREIGN KEY (organization_unit_id) REFERENCES organization_units (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9F85E0677 ON users (username)');
            $this->addSql('CREATE INDEX IDX_1483A5E9356FF84E ON users (organization_unit_id)');
            $this->addSql('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, event VARCHAR(80) NOT NULL, success BOOLEAN NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent CLOB DEFAULT NULL, context CLOB NOT NULL, created_at DATETIME NOT NULL, user_id INTEGER DEFAULT NULL, CONSTRAINT FK_D62F2858A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, token_hash VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_3967A216A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX IDX_3967A216A76ED395 ON password_reset_tokens (user_id)');
            $this->addSql('CREATE TABLE reference_items (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, category VARCHAR(255) NOT NULL, code VARCHAR(100) NOT NULL, label VARCHAR(180) NOT NULL, description CLOB DEFAULT NULL, ui_color VARCHAR(30) DEFAULT NULL, display_order INTEGER DEFAULT 0 NOT NULL, active BOOLEAN DEFAULT 1 NOT NULL)');
            $this->addSql('CREATE UNIQUE INDEX reference_category_code_unique ON reference_items (category, code)');
            $this->addSql('CREATE TABLE user_access_roles (user_id INTEGER NOT NULL, access_role_id INTEGER NOT NULL, PRIMARY KEY (user_id, access_role_id), CONSTRAINT FK_DF672E96A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_DF672E961E26F8B9 FOREIGN KEY (access_role_id) REFERENCES access_roles (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX IDX_DF672E96A76ED395 ON user_access_roles (user_id)');
            $this->addSql('CREATE INDEX IDX_DF672E961E26F8B9 ON user_access_roles (access_role_id)');
            return;
        }

        // MySQL / MariaDB DDL
        $this->addSql('CREATE TABLE access_permissions (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(100) NOT NULL, label VARCHAR(160) NOT NULL, module VARCHAR(80) NOT NULL, active TINYINT DEFAULT 1 NOT NULL, UNIQUE INDEX UNIQ_B778414177153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE access_roles (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(100) NOT NULL, label VARCHAR(160) NOT NULL, description LONGTEXT DEFAULT NULL, UNIQUE INDEX UNIQ_5FAB529477153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE access_role_permissions (access_role_id INT NOT NULL, access_permission_id INT NOT NULL, INDEX IDX_E0E73EE1E26F8B9 (access_role_id), INDEX IDX_E0E73EE66D760EE (access_permission_id), PRIMARY KEY (access_role_id, access_permission_id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE administrative_circuits (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(100) NOT NULL, label VARCHAR(180) NOT NULL, description LONGTEXT DEFAULT NULL, active TINYINT DEFAULT 1 NOT NULL, UNIQUE INDEX UNIQ_8D0A9B5777153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE organization_units (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(60) NOT NULL, name VARCHAR(180) NOT NULL, type VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, active TINYINT DEFAULT 1 NOT NULL, display_order INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, parent_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_6737928E77153098 (code), INDEX IDX_6737928E727ACA70 (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE administrative_circuit_steps (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, label VARCHAR(120) DEFAULT NULL, circuit_id INT NOT NULL, organization_unit_id INT NOT NULL, UNIQUE INDEX circuit_position_unique (circuit_id, position), INDEX IDX_2784D991CF2182C8 (circuit_id), INDEX IDX_2784D991356FF84E (organization_unit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, phone VARCHAR(40) DEFAULT NULL, username VARCHAR(100) DEFAULT NULL, password VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, last_login_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, disabled_at DATETIME DEFAULT NULL, organization_unit_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_1483A5E9E7927C74 (email), UNIQUE INDEX UNIQ_1483A5E9F85E0677 (username), INDEX IDX_1483A5E9356FF84E (organization_unit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE audit_logs (id INT AUTO_INCREMENT NOT NULL, event VARCHAR(80) NOT NULL, success TINYINT NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent LONGTEXT DEFAULT NULL, context JSON NOT NULL, created_at DATETIME NOT NULL, user_id INT DEFAULT NULL, INDEX idx_audit_logs_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE password_reset_tokens (id INT AUTO_INCREMENT NOT NULL, token_hash VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_3967A216A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE reference_items (id INT AUTO_INCREMENT NOT NULL, category VARCHAR(255) NOT NULL, code VARCHAR(100) NOT NULL, label VARCHAR(180) NOT NULL, description LONGTEXT DEFAULT NULL, ui_color VARCHAR(30) DEFAULT NULL, display_order INT DEFAULT 0 NOT NULL, active TINYINT DEFAULT 1 NOT NULL, UNIQUE INDEX reference_category_code_unique (category, code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE user_access_roles (user_id INT NOT NULL, access_role_id INT NOT NULL, INDEX IDX_DF672E96A76ED395 (user_id), INDEX IDX_DF672E961E26F8B9 (access_role_id), PRIMARY KEY (user_id, access_role_id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');

        $this->addSql('ALTER TABLE access_role_permissions ADD CONSTRAINT FK_E0E73EE1E26F8B9 FOREIGN KEY (access_role_id) REFERENCES access_roles (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE access_role_permissions ADD CONSTRAINT FK_E0E73EE66D760EE FOREIGN KEY (access_permission_id) REFERENCES access_permissions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE organization_units ADD CONSTRAINT FK_6737928E727ACA70 FOREIGN KEY (parent_id) REFERENCES organization_units (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE administrative_circuit_steps ADD CONSTRAINT FK_2784D991CF2182C8 FOREIGN KEY (circuit_id) REFERENCES administrative_circuits (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE administrative_circuit_steps ADD CONSTRAINT FK_2784D991356FF84E FOREIGN KEY (organization_unit_id) REFERENCES organization_units (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E9356FF84E FOREIGN KEY (organization_unit_id) REFERENCES organization_units (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE audit_logs ADD CONSTRAINT FK_D62F2858A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE password_reset_tokens ADD CONSTRAINT FK_3967A216A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_access_roles ADD CONSTRAINT FK_DF672E96A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_access_roles ADD CONSTRAINT FK_DF672E961E26F8B9 FOREIGN KEY (access_role_id) REFERENCES access_roles (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_access_roles');
        $this->addSql('DROP TABLE password_reset_tokens');
        $this->addSql('DROP TABLE audit_logs');
        $this->addSql('DROP TABLE administrative_circuit_steps');
        $this->addSql('DROP TABLE administrative_circuits');
        $this->addSql('DROP TABLE access_role_permissions');
        $this->addSql('DROP TABLE access_permissions');
        $this->addSql('DROP TABLE access_roles');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE organization_units');
        $this->addSql('DROP TABLE reference_items');
    }
}
