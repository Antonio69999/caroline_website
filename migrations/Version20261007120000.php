<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Crée la table bloc : les blocs (texte, image, son, vidéo) d'une catégorie de
 * type « vitrine » (le Préambule). Purement additive : aucune table existante
 * n'est modifiée, les catégories actuelles ne changent donc pas.
 */
final class Version20261007120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create bloc table (text/image/sound/video blocks of a "vitrine" category)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE bloc (id INT AUTO_INCREMENT NOT NULL, categorie_id INT NOT NULL, type VARCHAR(20) DEFAULT 'texte' NOT NULL, position INT DEFAULT 0 NOT NULL, titre VARCHAR(255) DEFAULT NULL, contenu LONGTEXT DEFAULT NULL, fichier_name VARCHAR(255) DEFAULT NULL, largeur VARCHAR(20) DEFAULT 'pleine' NOT NULL, modifie_le DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_C778955ABCF5E72D (categorie_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE bloc ADD CONSTRAINT FK_C778955ABCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bloc DROP FOREIGN KEY FK_C778955ABCF5E72D');
        $this->addSql('DROP TABLE bloc');
    }
}
