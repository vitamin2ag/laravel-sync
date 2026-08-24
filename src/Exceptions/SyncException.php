<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Exceptions;

use RuntimeException;
use ValueError;

class SyncException extends RuntimeException
{
    /**
     * No operation was given and none could be prompted for.
     */
    public static function operationRequired(): self
    {
        return new self('You must specify an operation: "push" or "pull".');
    }

    /**
     * The given operation string is not a valid operation.
     */
    public static function invalidOperation(ValueError $exception): self
    {
        return new self($exception->getMessage());
    }

    /**
     * No remote was given and none could be prompted for.
     */
    public static function remoteRequired(): self
    {
        return new self('You must specify a remote.');
    }

    /**
     * No remotes are defined in the package config.
     */
    public static function noRemotesConfigured(): self
    {
        return new self('You need to define at least one remote in your config/sync.php file.');
    }

    /**
     * No recipes are defined in the package config.
     */
    public static function noRecipesConfigured(): self
    {
        return new self('You need to define at least one recipe in your config/sync.php file.');
    }

    /**
     * The given remote name does not exist in the config.
     */
    public static function unknownRemote(string $name): self
    {
        return new self(sprintf('The remote "%s" is not defined in your config/sync.php file.', $name));
    }

    /**
     * The given recipe name does not exist in the config.
     */
    public static function unknownRecipe(string $name): self
    {
        return new self(sprintf('The recipe "%s" is not defined in your config/sync.php file.', $name));
    }

    /**
     * The given remote is read-only and cannot be pushed to.
     */
    public static function remoteIsReadOnly(string $name): self
    {
        return new self(sprintf('The remote "%s" is read-only and cannot be pushed to.', $name));
    }

    /**
     * No recipe was selected and `--all` was not passed.
     */
    public static function noRecipeSelected(): self
    {
        return new self('You must select at least one recipe, or pass --all to sync every recipe.');
    }

    /**
     * The resolved local and remote path for a recipe path are identical.
     */
    public static function samePath(string $path): self
    {
        return new self(sprintf('The origin and target path for "%s" are the same. Refusing to sync a path with itself.', $path));
    }

    /**
     * The backup directory is the same as, or nested inside, a recipe path being backed up.
     */
    public static function backupDirNested(string $dir, string $path): self
    {
        return new self(sprintf(
            'The backup directory "%s" is the same as, or inside, the recipe path "%s". Choose a backup_dir outside the recipe paths you back up.',
            $dir,
            $path,
        ));
    }

    /**
     * The configured backup directory resolves outside the project, or to the project
     * root itself — refused whether it's about to be written to (a backed-up pull) or
     * cleaned (`sync:backups-clean`).
     */
    public static function backupDirUnsafe(string $dir): self
    {
        return new self(sprintf(
            'The backup directory "%s" resolves outside your project, or to the project root itself. Set a backup_dir inside your project.',
            $dir,
        ));
    }

    /**
     * No backup was selected and `--all` was not passed.
     */
    public static function noBackupSelected(): self
    {
        return new self('You must select at least one backup, or pass --all, --keep, or --older-than to select which backups to delete.');
    }

    /**
     * `--keep` or `--older-than` was given something other than a non-negative integer.
     */
    public static function invalidRetentionValue(string $option, string $value): self
    {
        return new self(sprintf('The --%s option must be a non-negative integer, got "%s".', $option, $value));
    }

    /**
     * `--all` was combined with `--keep` or `--older-than` — both already select which
     * backups to delete, so combining them is ambiguous rather than additive.
     */
    public static function conflictingBackupSelection(): self
    {
        return new self('You cannot combine --all with --keep or --older-than — they already select which backups to delete.');
    }

    /**
     * `--older-than` was given a value beyond what a real retention window needs —
     * refused outright rather than risking day-arithmetic overflow silently inverting
     * its intent (see `SyncBackupsCleanCommand::MAX_OLDER_THAN_DAYS`).
     */
    public static function retentionValueTooLarge(string $option, string $value, int $max): self
    {
        return new self(sprintf('The --%s option must be at most %d, got "%s".', $option, $max, $value));
    }

    /**
     * `SyncLock::acquire()` can't distinguish "another run holds it" from "the lock file
     * couldn't be created", so the message covers both.
     */
    public static function lockUnavailable(string $remote): self
    {
        return new self(sprintf(
            'Could not start a sync for "%s": another sync may already be running for it. '.
            'Wait for it to finish, then try again — if this keeps happening, check that '.
            'the sync lock directory under your app\'s storage path is writable.',
            $remote,
        ));
    }

    /**
     * A recipe's configured `excludes_from` file doesn't exist on disk.
     */
    public static function excludesFromFileMissing(string $recipe, string $path): self
    {
        return new self(sprintf(
            'The excludes_from file "%s" configured for recipe "%s" does not exist.',
            $path,
            $recipe,
        ));
    }
}
