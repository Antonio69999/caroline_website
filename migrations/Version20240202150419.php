<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240202150419 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE media ADD image_name VARCHAR(255) DEFAULT NULL, ADD modifie_le DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE crée_le cree_le DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE media ADD crée_le DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', DROP image_name, DROP cree_le, DROP modifie_le');
    }
}
