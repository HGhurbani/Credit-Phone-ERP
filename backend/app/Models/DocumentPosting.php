<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentPosting extends Model
{
    protected $fillable = [
        'tenant_id',
        'source_type',
        'source_id',
        'event',
        'journal_entry_id',
        'reversal_entry_id',
        'posted_at',
        'reversed_at',
    ];

    protected $casts = [
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_entry_id');
    }
}
