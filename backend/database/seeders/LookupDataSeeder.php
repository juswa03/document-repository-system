<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\RequestType;
use Illuminate\Database\Seeder;

class LookupDataSeeder extends Seeder
{
    /**
     * category_code stays stable across renames (it's what tracking
     * numbers and existing document rows key off), only category_name
     * changed to the fuller wording below. Examples are documentation
     * only — Category has no description column to store them in.
     */
    private const CATEGORIES = [
        // Strategic plans, action plans, operational plans, scorecards, strategy maps
        ['category_name' => 'Strategic Planning Documents',                    'category_code' => 'STRAT'],
        // OPCR/IPCR files, accomplishment reports, APR documents, DBM reports
        ['category_name' => 'Performance Monitoring Documents',                'category_code' => 'PERF'],
        // AACCUP, ISO, QMS, institutional accreditation evidence
        ['category_name' => 'Accreditation and Quality Assurance Documents',   'category_code' => 'ACCR'],
        // QS Stars, UI GreenMetric, WURI, THE Impact Rankings, supporting evidence
        ['category_name' => 'Rankings and Internationalization Documents',     'category_code' => 'RANK'],
        // MSGC files, resolutions, minutes of meetings, office orders, committee documents
        ['category_name' => 'Governance Documents',                           'category_code' => 'GOV'],
        // LUDIP, PIP/TRIP, RDC submissions, infrastructure project documents
        ['category_name' => 'Infrastructure and Development Planning Documents', 'category_code' => 'INFRA'],
        // CHED, DBM, NEDA, AO25, ARTA, and other compliance documents
        ['category_name' => 'Compliance and Regulatory Documents',            'category_code' => 'COMP'],
        // Letter templates, report templates, monitoring forms, routing slips
        ['category_name' => 'Templates and Controlled Forms',                 'category_code' => 'TMPL'],
        // Memos, communications, endorsements, transmittals, internal reports
        ['category_name' => 'Administrative Documents',                       'category_code' => 'ADMIN'],
        // Superseded, completed, historical, or inactive files
        ['category_name' => 'Archived Documents',                             'category_code' => 'ARCH'],
    ];

    /**
     * Request types, ordered quickest turnaround first so the dropdown
     * reads as an escalating list. Lead times are in WORKING days and are
     * advisory — they set the requester's expectation and feed the aging
     * report; nothing blocks on them.
     *
     * These describe WHAT IS BEING REQUESTED (a document, a dataset,
     * something sensitive). They replace an earlier set that described
     * administrative transactions instead — see DEPRECATED_TYPE_CODES.
     */
    private const REQUEST_TYPES = [
        [
            'type_code' => 'SDR',
            'type_name' => 'Simple document request',
            'examples' => 'Approved plans, forms, templates, published reports, office issuances.',
            'lead_min_days' => 1,
            'lead_max_days' => 3,
            'requires_justification' => false,
            'display_order' => 10,
        ],
        [
            'type_code' => 'URG',
            'type_name' => 'Urgent request',
            'examples' => 'For meetings, compliance deadlines, or executive instruction.',
            'lead_min_days' => 1,
            'lead_max_days' => 2,
            // "Subject to approval" — the requester must say why it is
            // urgent, and the reviewer decides whether that stands.
            'requires_justification' => true,
            'display_order' => 20,
        ],
        [
            'type_code' => 'STD',
            'type_name' => 'Standard data request',
            'examples' => 'Performance indicators, accomplishment data, enrollment-related '
                .'planning data, office deliverables.',
            'lead_min_days' => 3,
            'lead_max_days' => 5,
            'requires_justification' => false,
            'display_order' => 30,
        ],
        [
            'type_code' => 'CPX',
            'type_name' => 'Complex data request',
            'examples' => 'Data requiring consolidation, validation from other offices, '
                .'historical comparison, or formatting.',
            'lead_min_days' => 7,
            'lead_max_days' => 10,
            'requires_justification' => false,
            'display_order' => 40,
        ],
        [
            'type_code' => 'SEN',
            'type_name' => 'Sensitive / confidential request',
            'examples' => 'Unpublished reports, accreditation or ranking evidence, internal '
                .'evaluation results, personally identifiable information.',
            'lead_min_days' => 10,
            'lead_max_days' => 15,
            // Sensitive material needs a stated purpose before release,
            // for the same reason urgency does.
            'requires_justification' => true,
            'display_order' => 50,
        ],
    ];

    /**
     * The administrative transaction types this repository no longer
     * handles. Deactivated rather than deleted: requests.request_type_id
     * is a foreign key, so existing records must keep resolving. An
     * inactive type is hidden from the submission form and refused on new
     * submissions, while old requests still read correctly.
     */
    private const DEPRECATED_TYPE_CODES = ['LVE', 'SUP', 'TRV', 'BUD', 'OTH'];

    public function run(): void
    {
        foreach (self::REQUEST_TYPES as $type) {
            RequestType::updateOrCreate(
                ['type_code' => $type['type_code']],
                $type + ['is_active' => true],
            );
        }

        RequestType::whereIn('type_code', self::DEPRECATED_TYPE_CODES)
            ->update(['is_active' => false]);

        foreach (self::CATEGORIES as $category) {
            Category::updateOrCreate(['category_code' => $category['category_code']], $category);
        }
    }
}
