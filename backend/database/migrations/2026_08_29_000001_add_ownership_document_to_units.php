<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proof the partner may actually list the property — a title deed (صك ملكية)
 * or, for a partner who rents and sublets, a lease contract (عقد إيجار).
 *
 * Nullable and NOT required at submission. Every existing listing was approved
 * without one, so making it mandatory now would freeze every draft and every
 * resubmission on the platform behind a document nobody was asked for. Turning
 * it into a submit requirement later is one line in UnitWriter::submitErrors().
 *
 * Holds either a DashboardUpload id (`file_...`, from the presign flow the
 * Next.js dashboard uses) or a storage path (from the direct multipart upload
 * on /api/v1). DashboardUpload::resolveUrl() already reads both, which is why
 * the two surfaces can share one column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->string('ownership_doc_file')->nullable()->after('tourism_permit_file');
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('ownership_doc_file');
        });
    }
};
