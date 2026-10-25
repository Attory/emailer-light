<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** A missing template is real incomplete state, never a fabricated template. */
final class Version20261025000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Permit incomplete campaign drafts without a selected template; preserve all existing content and audiences.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaign MODIFY template_version_id CHAR(36) DEFAULT NULL');
        $this->addSql("UPDATE product_identity SET schema_version = '20261025000000' WHERE singleton_key = '1'");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Restore the verified pre-update recovery set; incomplete drafts cannot use the older schema.');
    }
}
