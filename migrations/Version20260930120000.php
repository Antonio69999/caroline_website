<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute categorie.type et categorie.contenu pour permettre des catégories de
 * type « texte » (ex. le CV de l'artiste) en plus des galeries d'articles.
 * Rétrocompatible : type vaut 'galerie' par défaut, donc toutes les catégories
 * existantes gardent exactement leur affichage actuel sans aucune action.
 */
final class Version20260930120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Add categorie.type ('galerie' by default) and categorie.contenu (rich text for text-only categories like the CV)";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE categorie ADD type VARCHAR(20) DEFAULT 'galerie' NOT NULL, ADD contenu LONGTEXT DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE categorie DROP type, DROP contenu');
    }
}
