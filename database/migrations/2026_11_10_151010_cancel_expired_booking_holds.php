<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A hold that ran out is a cancellation, not a separate final state.
     */
    public function up(): void
    {
        DB::table('bookings')
            ->where('status', 'expired')
            ->update([
                'status' => 'cancelled',
                'hold_expires_at' => null,
                'updated_at' => now(),
            ]);

        DB::table('booking_status_histories')
            ->where('from_status', 'expired')
            ->update(['from_status' => 'cancelled']);

        DB::table('booking_status_histories')
            ->where('to_status', 'expired')
            ->update(['to_status' => 'cancelled']);
    }

    /**
     * The original state cannot be restored without guessing which cancellations
     * came from a hold, so leave the corrected records in place.
     */
    public function down(): void
    {
        //
    }
};
