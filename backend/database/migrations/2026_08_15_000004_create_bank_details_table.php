<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The payout account — wallet contract §6.
 *
 * Needed by BOTH account types. Until now the only IBAN that reached a partner
 * record was written by PUT /me/company-docs, which individuals never fill in,
 * so an individual partner could not be paid at all.
 *
 * `partner_details.iban` stays in sync (written through by the controller)
 * because the admin KYC screen and documentsComplete() read it. This table is
 * the source of truth for payouts; that column remains the KYC view of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('iban', 34);
            $table->string('account_holder_name', 120);
            $table->string('bank_name')->nullable();     // derived from the IBAN bank code
            $table->boolean('verified')->default(false); // manual finance step
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();
        });

        // Carry over IBANs already captured through the company-docs form so no
        // partner has to re-enter one. They arrive unverified: nobody has
        // checked them against this table's meaning of verified.
        $existing = DB::table('partner_details')
            ->join('users', 'users.id', '=', 'partner_details.user_id')
            ->whereNotNull('partner_details.iban')
            ->where('partner_details.iban', '!=', '')
            ->select('partner_details.user_id', 'partner_details.iban', 'users.name')
            ->get();

        $now = now();

        foreach ($existing as $row) {
            DB::table('bank_details')->insert([
                'partner_user_id'     => $row->user_id,
                'iban'                => strtoupper(preg_replace('/\s+/', '', $row->iban)),
                'account_holder_name' => $row->name ?: 'غير محدد',
                'bank_name'           => null,
                'verified'            => false,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_details');
    }
};
