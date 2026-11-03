<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration S5.10 — Alignement complet du schéma MySQL (Sprints 2 à 5) :
 * Courriers entrants/sortants, Séquences, Dossiers, Événements timeline, Transmissions,
 * Documents, Notifications (avec idempotence), Expéditeurs, Moteur de Workflow et Échéances.
 */
final class Version20261030103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée les tables métier Sprints 2 à 5 (cases, correspondences, documents, workflows, notifications, senders) et aligne le schéma MySQL.';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SqlitePlatform) {
            return;
        }

        $this->addSql('ALTER TABLE administrative_circuit_steps ADD standard_duration_hours INT DEFAULT NULL');

        $this->addSql('CREATE TABLE senders (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(255) NOT NULL, name VARCHAR(200) NOT NULL, organisation VARCHAR(200) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, address LONGTEXT DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');

        $this->addSql('CREATE TABLE cases (id INT AUTO_INCREMENT NOT NULL, reference VARCHAR(20) NOT NULL, subject VARCHAR(500) NOT NULL, status_code VARCHAR(40) NOT NULL, priority_code VARCHAR(40) DEFAULT NULL, entry_channel VARCHAR(30) NOT NULL, received_at DATETIME NOT NULL, deadline DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, archived_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, sender_id INT DEFAULT NULL, case_type_id INT DEFAULT NULL, current_structure_id INT DEFAULT NULL, current_assignee_id INT DEFAULT NULL, circuit_id INT DEFAULT NULL, closed_by_id INT DEFAULT NULL, archived_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_1C1B038BAEA34913 (reference), INDEX idx_case_reference (reference), INDEX idx_case_status (status_code), INDEX idx_case_priority (priority_code), INDEX idx_case_current_structure (current_structure_id), INDEX idx_case_current_assignee (current_assignee_id), INDEX IDX_1C1B038BF624B39D (sender_id), INDEX IDX_1C1B038B6AEF7998 (case_type_id), INDEX IDX_1C1B038BCF2182C8 (circuit_id), INDEX IDX_1C1B038BE1FA7797 (closed_by_id), INDEX IDX_1C1B038B77BE2925 (archived_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');

        $this->addSql('CREATE TABLE case_events (id INT AUTO_INCREMENT NOT NULL, event_type VARCHAR(255) NOT NULL, action VARCHAR(50) DEFAULT NULL, label VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, metadata JSON DEFAULT NULL, old_value VARCHAR(255) DEFAULT NULL, new_value VARCHAR(255) DEFAULT NULL, occurred_at DATETIME NOT NULL, case_id INT NOT NULL, actor_id INT DEFAULT NULL, organization_unit_id INT DEFAULT NULL, INDEX idx_case_events_case (case_id), INDEX idx_case_events_date (occurred_at), INDEX idx_case_events_action (action), INDEX idx_case_events_actor (actor_id), INDEX IDX_FA6910E3356FF84E (organization_unit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');

        $this->addSql('CREATE TABLE case_transmissions (id INT AUTO_INCREMENT NOT NULL, reason VARCHAR(100) DEFAULT NULL, instructions LONGTEXT DEFAULT NULL, transmitted_at DATETIME NOT NULL, case_id INT NOT NULL, source_unit_id INT DEFAULT NULL, target_unit_id INT DEFAULT NULL, transmitted_by_id INT DEFAULT NULL, INDEX IDX_1C28459FCF10D4F5 (case_id), INDEX IDX_1C28459FB162C865 (source_unit_id), INDEX IDX_1C28459F33D5C966 (target_unit_id), INDEX IDX_1C28459F844E03E1 (transmitted_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');

        $this->addSql('CREATE TABLE correspondence_sequences (year INT NOT NULL, last_value INT DEFAULT 0 NOT NULL, last_outgoing_value INT DEFAULT 0 NOT NULL, PRIMARY KEY (year)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');

        $this->addSql('CREATE TABLE correspondences (id INT AUTO_INCREMENT NOT NULL, reference VARCHAR(25) DEFAULT NULL, subject VARCHAR(500) NOT NULL, entry_channel VARCHAR(30) NOT NULL, status VARCHAR(255) NOT NULL, recipient_name VARCHAR(200) DEFAULT NULL, recipient_organisation VARCHAR(200) DEFAULT NULL, dispatch_method VARCHAR(60) DEFAULT NULL, received_at DATETIME NOT NULL, registered_at DATETIME DEFAULT NULL, validated_at DATETIME DEFAULT NULL, released_at DATETIME DEFAULT NULL, observations LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, document_type_id INT DEFAULT NULL, mail_type_id INT DEFAULT NULL, priority_id INT DEFAULT NULL, sender_id INT DEFAULT NULL, issuing_structure_id INT DEFAULT NULL, registered_by_id INT DEFAULT NULL, validated_by_id INT DEFAULT NULL, case_id INT DEFAULT NULL, main_document_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_D0457CC1AEA34913 (reference), UNIQUE INDEX UNIQ_D0457CC117BCB0A1 (main_document_id), INDEX idx_correspondence_reference (reference), INDEX idx_correspondence_received_at (received_at), INDEX idx_correspondence_status (status), INDEX idx_correspondence_channel (entry_channel), INDEX IDX_D0457CC161232A4F (document_type_id), INDEX IDX_D0457CC11FF10DFC (mail_type_id), INDEX IDX_D0457CC1497B19F9 (priority_id), INDEX IDX_D0457CC1F624B39D (sender_id), INDEX IDX_D0457CC1627D15A8 (issuing_structure_id), INDEX IDX_D0457CC127E92E18 (registered_by_id), INDEX IDX_D0457CC1C69DE5E5 (validated_by_id), INDEX IDX_D0457CC1CF10D4F5 (case_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');

        $this->addSql('CREATE TABLE documents (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(100) NOT NULL, original_name VARCHAR(255) NOT NULL, storage_name VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size INT NOT NULL, path VARCHAR(255) NOT NULL, version INT DEFAULT 1 NOT NULL, uploaded_at DATETIME NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, case_id INT DEFAULT NULL, correspondence_id INT DEFAULT NULL, uploaded_by_id INT DEFAULT NULL, INDEX IDX_A2B07288CF10D4F5 (case_id), INDEX IDX_A2B0728853CD389 (correspondence_id), INDEX IDX_A2B07288A2B28FE8 (uploaded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');

        $this->addSql('CREATE TABLE notifications (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, message LONGTEXT NOT NULL, type VARCHAR(50) NOT NULL, icon VARCHAR(100) DEFAULT NULL, link VARCHAR(255) DEFAULT NULL, idempotency_key VARCHAR(120) DEFAULT NULL, is_read TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL, user_id INT NOT NULL, INDEX idx_user_read (user_id, is_read), INDEX idx_notification_created (created_at), INDEX IDX_6000B0D3A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');

        $this->addSql('CREATE TABLE workflows (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(100) NOT NULL, name VARCHAR(180) NOT NULL, description LONGTEXT DEFAULT NULL, active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_EFBFBFC277153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');

        $this->addSql('CREATE TABLE workflow_steps (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(100) NOT NULL, name VARCHAR(180) NOT NULL, position INT NOT NULL, is_initial TINYINT DEFAULT 0 NOT NULL, is_final TINYINT DEFAULT 0 NOT NULL, active TINYINT DEFAULT 1 NOT NULL, standard_duration_hours INT DEFAULT NULL, workflow_id INT NOT NULL, target_structure_id INT DEFAULT NULL, target_role_id INT DEFAULT NULL, UNIQUE INDEX workflow_step_code_unique (workflow_id, code), UNIQUE INDEX workflow_step_pos_unique (workflow_id, position), INDEX IDX_856C7C462C7C2CBA (workflow_id), INDEX IDX_856C7C46C566D3DA (target_structure_id), INDEX IDX_856C7C461D6B9BC7 (target_role_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');

        $this->addSql('CREATE TABLE workflow_instances (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(40) NOT NULL, started_at DATETIME NOT NULL, step_entered_at DATETIME NOT NULL, step_deadline DATETIME DEFAULT NULL, completed_at DATETIME DEFAULT NULL, updated_at DATETIME NOT NULL, case_id INT NOT NULL, workflow_id INT NOT NULL, current_step_id INT NOT NULL, UNIQUE INDEX UNIQ_878E9AECCF10D4F5 (case_id), INDEX idx_instance_workflow (workflow_id), INDEX idx_instance_current_step (current_step_id), INDEX idx_instance_status (status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');

        $this->addSql('CREATE TABLE workflow_histories (id INT AUTO_INCREMENT NOT NULL, action VARCHAR(40) NOT NULL, comment LONGTEXT DEFAULT NULL, metadata JSON DEFAULT NULL, occurred_at DATETIME NOT NULL, duration_seconds INT DEFAULT NULL, instance_id INT NOT NULL, source_step_id INT DEFAULT NULL, target_step_id INT NOT NULL, actor_id INT DEFAULT NULL, organization_unit_id INT DEFAULT NULL, INDEX idx_history_instance (instance_id), INDEX idx_history_action (action), INDEX idx_history_occurred (occurred_at), INDEX IDX_325854263A6DA6F4 (source_step_id), INDEX IDX_32585426B8DAA7F7 (target_step_id), INDEX IDX_3258542610DAF24A (actor_id), INDEX IDX_32585426356FF84E (organization_unit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');

        $this->addSql('CREATE TABLE workflow_tasks (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(40) NOT NULL, instructions LONGTEXT DEFAULT NULL, deadline DATETIME DEFAULT NULL, escalated_at DATETIME DEFAULT NULL, started_at DATETIME DEFAULT NULL, completed_at DATETIME DEFAULT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, instance_id INT NOT NULL, step_id INT NOT NULL, target_structure_id INT DEFAULT NULL, target_user_id INT DEFAULT NULL, completed_by_id INT DEFAULT NULL, INDEX idx_task_instance (instance_id), INDEX idx_task_step (step_id), INDEX idx_task_structure (target_structure_id), INDEX idx_task_user (target_user_id), INDEX idx_task_status (status), INDEX IDX_E11613A385ECDE76 (completed_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');

        $this->addSql('CREATE TABLE workflow_transitions (id INT AUTO_INCREMENT NOT NULL, action VARCHAR(40) NOT NULL, label VARCHAR(180) NOT NULL, required_permission VARCHAR(100) DEFAULT NULL, comment_required TINYINT DEFAULT 0 NOT NULL, is_return TINYINT DEFAULT 0 NOT NULL, active TINYINT DEFAULT 1 NOT NULL, workflow_id INT NOT NULL, source_step_id INT NOT NULL, target_step_id INT NOT NULL, authorized_role_id INT DEFAULT NULL, INDEX idx_trans_workflow (workflow_id), INDEX idx_trans_source (source_step_id), INDEX idx_trans_target (target_step_id), INDEX IDX_C66989BB21C74823 (authorized_role_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');

        $this->addSql('ALTER TABLE cases ADD CONSTRAINT FK_1C1B038BF624B39D FOREIGN KEY (sender_id) REFERENCES senders (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cases ADD CONSTRAINT FK_1C1B038B6AEF7998 FOREIGN KEY (case_type_id) REFERENCES reference_items (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cases ADD CONSTRAINT FK_1C1B038BDA85BE50 FOREIGN KEY (current_structure_id) REFERENCES organization_units (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cases ADD CONSTRAINT FK_1C1B038BE738D6B9 FOREIGN KEY (current_assignee_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cases ADD CONSTRAINT FK_1C1B038BCF2182C8 FOREIGN KEY (circuit_id) REFERENCES administrative_circuits (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cases ADD CONSTRAINT FK_1C1B038BE1FA7797 FOREIGN KEY (closed_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cases ADD CONSTRAINT FK_1C1B038B77BE2925 FOREIGN KEY (archived_by_id) REFERENCES users (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE case_events ADD CONSTRAINT FK_FA6910E3CF10D4F5 FOREIGN KEY (case_id) REFERENCES cases (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE case_events ADD CONSTRAINT FK_FA6910E310DAF24A FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE case_events ADD CONSTRAINT FK_FA6910E3356FF84E FOREIGN KEY (organization_unit_id) REFERENCES organization_units (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE case_transmissions ADD CONSTRAINT FK_1C28459FCF10D4F5 FOREIGN KEY (case_id) REFERENCES cases (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE case_transmissions ADD CONSTRAINT FK_1C28459FB162C865 FOREIGN KEY (source_unit_id) REFERENCES organization_units (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE case_transmissions ADD CONSTRAINT FK_1C28459F33D5C966 FOREIGN KEY (target_unit_id) REFERENCES organization_units (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE case_transmissions ADD CONSTRAINT FK_1C28459F844E03E1 FOREIGN KEY (transmitted_by_id) REFERENCES users (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE correspondences ADD CONSTRAINT FK_D0457CC161232A4F FOREIGN KEY (document_type_id) REFERENCES reference_items (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE correspondences ADD CONSTRAINT FK_D0457CC11FF10DFC FOREIGN KEY (mail_type_id) REFERENCES reference_items (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE correspondences ADD CONSTRAINT FK_D0457CC1497B19F9 FOREIGN KEY (priority_id) REFERENCES reference_items (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE correspondences ADD CONSTRAINT FK_D0457CC1F624B39D FOREIGN KEY (sender_id) REFERENCES senders (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE correspondences ADD CONSTRAINT FK_D0457CC1627D15A8 FOREIGN KEY (issuing_structure_id) REFERENCES organization_units (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE correspondences ADD CONSTRAINT FK_D0457CC127E92E18 FOREIGN KEY (registered_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE correspondences ADD CONSTRAINT FK_D0457CC1C69DE5E5 FOREIGN KEY (validated_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE correspondences ADD CONSTRAINT FK_D0457CC1CF10D4F5 FOREIGN KEY (case_id) REFERENCES cases (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE correspondences ADD CONSTRAINT FK_D0457CC117BCB0A1 FOREIGN KEY (main_document_id) REFERENCES documents (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE documents ADD CONSTRAINT FK_A2B07288CF10D4F5 FOREIGN KEY (case_id) REFERENCES cases (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT FK_A2B0728853CD389 FOREIGN KEY (correspondence_id) REFERENCES correspondences (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT FK_A2B07288A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES users (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_6000B0D3A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE workflow_steps ADD CONSTRAINT FK_856C7C462C7C2CBA FOREIGN KEY (workflow_id) REFERENCES workflows (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workflow_steps ADD CONSTRAINT FK_856C7C46C566D3DA FOREIGN KEY (target_structure_id) REFERENCES organization_units (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE workflow_steps ADD CONSTRAINT FK_856C7C461D6B9BC7 FOREIGN KEY (target_role_id) REFERENCES access_roles (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE workflow_instances ADD CONSTRAINT FK_878E9AECCF10D4F5 FOREIGN KEY (case_id) REFERENCES cases (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workflow_instances ADD CONSTRAINT FK_878E9AEC2C7C2CBA FOREIGN KEY (workflow_id) REFERENCES workflows (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE workflow_instances ADD CONSTRAINT FK_878E9AECD9BF9B19 FOREIGN KEY (current_step_id) REFERENCES workflow_steps (id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE workflow_histories ADD CONSTRAINT FK_325854263A51721D FOREIGN KEY (instance_id) REFERENCES workflow_instances (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workflow_histories ADD CONSTRAINT FK_325854263A6DA6F4 FOREIGN KEY (source_step_id) REFERENCES workflow_steps (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE workflow_histories ADD CONSTRAINT FK_32585426B8DAA7F7 FOREIGN KEY (target_step_id) REFERENCES workflow_steps (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workflow_histories ADD CONSTRAINT FK_3258542610DAF24A FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE workflow_histories ADD CONSTRAINT FK_32585426356FF84E FOREIGN KEY (organization_unit_id) REFERENCES organization_units (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE workflow_tasks ADD CONSTRAINT FK_E11613A33A51721D FOREIGN KEY (instance_id) REFERENCES workflow_instances (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workflow_tasks ADD CONSTRAINT FK_E11613A373B21E9C FOREIGN KEY (step_id) REFERENCES workflow_steps (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workflow_tasks ADD CONSTRAINT FK_E11613A3C566D3DA FOREIGN KEY (target_structure_id) REFERENCES organization_units (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE workflow_tasks ADD CONSTRAINT FK_E11613A36C066AFE FOREIGN KEY (target_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE workflow_tasks ADD CONSTRAINT FK_E11613A385ECDE76 FOREIGN KEY (completed_by_id) REFERENCES users (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE workflow_transitions ADD CONSTRAINT FK_C66989BB2C7C2CBA FOREIGN KEY (workflow_id) REFERENCES workflows (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workflow_transitions ADD CONSTRAINT FK_C66989BB3A6DA6F4 FOREIGN KEY (source_step_id) REFERENCES workflow_steps (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workflow_transitions ADD CONSTRAINT FK_C66989BBB8DAA7F7 FOREIGN KEY (target_step_id) REFERENCES workflow_steps (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workflow_transitions ADD CONSTRAINT FK_C66989BB21C74823 FOREIGN KEY (authorized_role_id) REFERENCES access_roles (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SqlitePlatform) {
            return;
        }

        $this->addSql('ALTER TABLE correspondences DROP FOREIGN KEY FK_D0457CC117BCB0A1');
        $this->addSql('ALTER TABLE documents DROP FOREIGN KEY FK_A2B0728853CD389');
        $this->addSql('DROP TABLE workflow_transitions');
        $this->addSql('DROP TABLE workflow_tasks');
        $this->addSql('DROP TABLE workflow_histories');
        $this->addSql('DROP TABLE workflow_instances');
        $this->addSql('DROP TABLE workflow_steps');
        $this->addSql('DROP TABLE workflows');
        $this->addSql('DROP TABLE notifications');
        $this->addSql('DROP TABLE documents');
        $this->addSql('DROP TABLE correspondences');
        $this->addSql('DROP TABLE correspondence_sequences');
        $this->addSql('DROP TABLE case_transmissions');
        $this->addSql('DROP TABLE case_events');
        $this->addSql('DROP TABLE cases');
        $this->addSql('DROP TABLE senders');
        $this->addSql('ALTER TABLE administrative_circuit_steps DROP COLUMN standard_duration_hours');
    }
}
