<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Crée la table accueil : les réglages de la page d'accueil (photo choisie par Caroline et son
 * cadrage). Purement additive : aucune table existante n'est modifiée, et tant que la table est
 * vide (ou sans photo) le site garde l'accueil d'origine.
 */
final class Version20261118120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create accueil table (home page photo chosen in the admin, with its framing)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE accueil (id INT AUTO_INCREMENT NOT NULL, image_name VARCHAR(255) DEFAULT NULL, image_mobile_name VARCHAR(255) DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, couleur_fond VARCHAR(7) DEFAULT '#ffffff' NOT NULL, bureau_mode VARCHAR(20) DEFAULT 'entiere' NOT NULL, bureau_x SMALLINT DEFAULT 50 NOT NULL, bureau_y SMALLINT DEFAULT 50 NOT NULL, bureau_zoom SMALLINT DEFAULT 100 NOT NULL, mobile_mode VARCHAR(20) DEFAULT 'plein' NOT NULL, mobile_x SMALLINT DEFAULT 50 NOT NULL, mobile_y SMALLINT DEFAULT 50 NOT NULL, mobile_zoom SMALLINT DEFAULT 100 NOT NULL, modifie_le DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE accueil');
    }
}
