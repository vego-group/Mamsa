<?php

declare(strict_types=1);

return [
    /*
     * How long a document link stays valid.
     *
     * Two hours, not the fifteen minutes the complaint attachments use, because
     * the two are read differently. An attachment is glanced at; a compliance
     * review is a person comparing a number on a screen against a number in a
     * PDF, opening another tab, and coming back. Fifteen minutes turns that
     * into the 403 we spent a week removing.
     *
     * The link is minted when a screen asks for it and never stored, so the
     * window only starts mattering when a page is left open longer than this.
     */
    'link_minutes' => (int) env('DOCUMENT_LINK_MINUTES', 120),

    /*
     * Where secured documents live, and where they used to live.
     *
     * Both are read during the migration: the signed route ships BEFORE the
     * files move, so it has to serve whichever disk a document is on. Once the
     * move is done `public` stops matching anything and can be dropped.
     */
    'vault_disk' => 'local',
    'vault_root' => 'secured-documents',
];
