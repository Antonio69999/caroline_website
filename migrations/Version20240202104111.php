<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240202104111 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article ADD cree_le DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD modifie_le DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', DROP crée_le, DROP modifiéle');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article ADD crée_le DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD modifiéle DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', DROP cree_le, DROP modifie_le');
    }
}
