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

    /*
     * Which upload kinds are compliance documents rather than public imagery.
     *
     * One list, read by the writers, the reader and the orphan sweep, because
     * the cost of them disagreeing is asymmetric: a document mistaken for a
     * photo is published, while a photo mistaken for a document merely goes
     * through PHP. `unit_photo` is the only kind deliberately absent.
     *
     * `national_id` appears here even though it is not a DashboardUpload::KINDS
     * value — registration writes ID scans into that folder under the
     * `company_doc` kind, so the DIRECTORY has to be covered too.
     */
    'sensitive_kinds' => ['license_pdf', 'company_doc', 'ownership_doc', 'national_id'],

    /*
     * Directories holding documents, for sweeps that work on paths rather than
     * rows — the orphan mover among them.
     */
    'sensitive_dirs' => [
        'dashboard/license_pdf',
        'dashboard/national_id',
        'dashboard/company_doc',
        'dashboard/ownership_doc',
    ],
];
