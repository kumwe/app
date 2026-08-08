<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

enum SchemaOperationKind: string
{
    case CreateTable = 'create_table';
    case RenameTable = 'rename_table';
    case DropTable = 'drop_table';
    case AddColumn = 'add_column';
    case AlterColumn = 'alter_column';
    case RenameColumn = 'rename_column';
    case DropColumn = 'drop_column';
    case AddPrimaryKey = 'add_primary_key';
    case DropPrimaryKey = 'drop_primary_key';
    case AddIndex = 'add_index';
    case DropIndex = 'drop_index';
    case AddForeignKey = 'add_foreign_key';
    case DropForeignKey = 'drop_foreign_key';
    case Backfill = 'backfill';
    case Transform = 'transform';
    case RepinRecords = 'repin_records';
    case ValidateConstraint = 'validate_constraint';
}
