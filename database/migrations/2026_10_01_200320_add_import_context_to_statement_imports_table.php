<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statement_imports', function (Blueprint $table): void {
            $table->renameColumn('source', 'institution');
        });

        Schema::table('statement_imports', function (Blueprint $table): void {
            $table->string('document_type', 30)->nullable()->after('status');
            $table->foreignId('account_id')->nullable()->after('institution')->constrained()->restrictOnDelete();
            $table->foreignId('credit_card_id')->nullable()->after('account_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('bill_due_year')->nullable()->after('credit_card_id');
            $table->unsignedTinyInteger('bill_due_month')->nullable()->after('bill_due_year');
            $table->char('file_sha256', 64)->nullable()->after('bill_due_month');
            $table->index(['user_id', 'file_sha256']);
        });
    }

    public function down(): void
    {
        Schema::table('statement_imports', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'file_sha256']);
            $table->dropConstrainedForeignId('credit_card_id');
            $table->dropConstrainedForeignId('account_id');
            $table->dropColumn(['document_type', 'bill_due_year', 'bill_due_month', 'file_sha256']);
        });

        Schema::table('statement_imports', function (Blueprint $table): void {
            $table->renameColumn('institution', 'source');
        });
    }
};
