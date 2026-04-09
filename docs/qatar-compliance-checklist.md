# Qatar Compliance Checklist

Last reviewed: 2026-04-09

This checklist converts the current Qatar-readiness assessment into an implementation guide for this repository.

Important:

- This is a product and engineering checklist, not legal advice.
- Legal sign-off should be completed by qualified Qatar counsel before production launch.
- The checklist below is based on official Qatar privacy and tax guidance reviewed on 2026-04-09, plus the current codebase state.

## 1. Current status

Overall status: partial readiness, not full compliance.

What is already aligned:

- Default currency is `QAR`: `backend/database/migrations/2026_01_01_000001_create_tenants_table.php`
- Default timezone is `Asia/Qatar`: `backend/database/migrations/2026_01_01_000001_create_tenants_table.php`
- Arabic UI and RTL are supported: `frontend/src/context/LangContext.jsx`
- Multi-tenant and branch isolation exist: `backend/routes/api.php`
- Permission middleware is broadly applied: `backend/routes/api.php`
- Audit logs exist: `backend/app/Models/AuditLog.php`
- Invoice and contract printing exist: `backend/resources/views/assistant/pdf/`
- Secrets such as AI and Telegram tokens are encrypted: `backend/app/Support/SettingsCatalog.php`, `backend/app/Http/Controllers/Api/SettingController.php`

What is not yet sufficient:

- No visible privacy notice, consent flow, rights workflow, or retention policy
- No documented breach-notification workflow
- Customer identity and salary data are stored as plain application fields
- Customer document compliance controls are not clearly implemented
- Company legal identifiers for Qatar are not modeled well enough for formal invoice compliance
- Invoice templates do not show tax or legal registration fields
- No Dhareeba-facing reporting/export readiness layer was found
- AI and Telegram integrations need privacy controls before production use with customer data

## 2. Priority order

Use this order for execution:

1. Privacy and personal-data governance
2. Invoice and legal-entity fields
3. Storage, encryption, and document access controls
4. Tax and accounting readiness for Qatar filings
5. External processor controls for AI and Telegram
6. Testing, evidence, and release sign-off

## 3. Action checklist

| Area | Requirement | Current state | Action needed | Suggested repo area |
|---|---|---|---|---|
| Privacy governance | Publish a privacy notice in Arabic and English | Missing | Add legal/privacy page and expose it in app login/footer/settings | `frontend/src/pages`, `frontend/src/App.jsx` |
| Privacy governance | Define lawful-use and purpose statements for customer data | Missing | Add policy text and admin-facing guidance for staff | `docs/`, `frontend/src/pages/settings` |
| Data subject rights | Access/correction/deletion request workflow | Missing | Add admin workflow to record and process requests | `backend/app/Http/Controllers/Api`, `frontend/src/pages/customers` |
| Data retention | Retention and deletion schedule for customers, notes, documents, audit data | Missing | Add documented retention matrix and scheduled cleanup rules | `docs/`, `backend/app/Console`, `backend/app/Jobs` |
| Breach handling | Personal-data breach notification process | Missing | Add incident runbook and internal admin workflow | `docs/` |
| Special-category review | Review whether salary, IDs, and uploaded documents need enhanced handling | Partial | Perform legal review and add stricter controls if required | `backend/database/migrations`, `backend/app/Models` |
| Encryption at rest | Encrypt sensitive customer identity and salary fields | Missing | Add model-level encryption or dedicated encrypted columns | `backend/app/Models/Customer.php`, related requests/resources |
| Document protection | Store customer documents in private storage with signed/authorized access only | Unclear/partial | Confirm storage path, move to private disk if needed, add download authorization | `backend/app/Http/Controllers/Api`, `backend/storage`, `config/filesystems.php` |
| Least privilege | Restrict access to customer PII by role and branch | Partial | Review all customer endpoints and resources for minimum necessary data exposure | `backend/routes/api.php`, customer controllers/resources |
| Auditability | Record who viewed/exported sensitive customer records | Partial | Expand audit logging beyond create/update/delete | `backend/app/Models/AuditLog.php`, controllers/services |
| Company legal profile | Capture company legal identifiers used in Qatar | Missing | Add fields for CR/license/tax card or approved legal identifiers | `frontend/src/pages/settings/SettingsPage.jsx`, settings backend |
| Invoice content | Show seller legal details on invoice | Partial | Add company legal fields to invoice header/footer | `backend/resources/views/assistant/pdf/layout.blade.php`, `invoice.blade.php` |
| Invoice numbering | Ensure tenant-safe, stable, auditable invoice numbering | Partial | Review numbering rules and cancellation handling | `backend/app/Services/InvoiceService.php` |
| Tax readiness | Keep tax model configurable for future VAT rollout | Partial | Add a configurable tax layer instead of hardcoded no-tax assumptions | `backend/app/Services`, invoice/order resources, frontend totals |
| Filing readiness | Keep records exportable for GTA/Dhareeba accounting support | Partial | Add export formats and month/year accounting packs | `backend/app/Http/Controllers/Api/ReportController.php`, export layer |
| Accounting evidence | Preserve books and source documents for required recordkeeping | Partial | Add document retention/export policy and admin download bundles | `backend/app/Services`, `docs/` |
| AI processors | Prevent sending customer data to OpenAI/Gemini without policy and approval | Missing control | Add tenant-level toggle, warnings, redaction, and approved-use policy | `frontend/src/pages/settings/SettingsPage.jsx`, assistant services |
| Messaging processors | Prevent uncontrolled customer-data sharing over Telegram | Missing control | Add explicit warning, disable by default, and limit payload content | `frontend/src/pages/settings/SettingsPage.jsx`, Telegram services |
| Cross-border review | Review external data transfer implications | Missing | Document processors, transfer basis, and internal approval gate | `docs/`, settings UI |
| Testing | Add compliance-oriented tests | Partial | Add tests for masking, encryption, private document access, and invoice legal fields | `backend/tests`, `frontend` tests if added later |

## 4. Recommended implementation phases

### Phase 1: Blockers before Qatar production launch

- Add privacy notice and internal privacy policy
- Add company legal identity fields to settings
- Display those fields on invoice PDFs and print pages
- Encrypt customer `national_id`, guarantor identity data, and salary-related fields
- Confirm customer documents are stored privately and only downloadable by authorized users
- Add audit logs for sensitive record viewing and document downloads
- Disable or gate AI and Telegram features for customer data until approved

### Phase 2: Operational compliance hardening

- Add data subject request workflow
- Add retention/deletion policy and cleanup jobs
- Add breach-response runbook
- Add accounting export bundles for filing support
- Add configurable tax engine for future VAT activation if Qatar implements domestic VAT rules

### Phase 3: Evidence and release readiness

- Add automated tests for all privacy-sensitive flows
- Create a compliance sign-off checklist for deployment
- Store versioned policy documents in `docs/`
- Record last legal review date and owner

## 5. Concrete backlog items for this repo

Create backlog tickets from the list below:

1. Add `company_cr_number`, `company_license_number`, and `company_tax_card_number` settings.
2. Render legal company identifiers in `backend/resources/views/assistant/pdf/layout.blade.php`.
3. Encrypt high-risk customer fields in `Customer`, `Guarantor`, and related resources.
4. Add a private document download endpoint with authorization and audit logging.
5. Add privacy notice pages in Arabic and English.
6. Add an internal `docs/privacy-operating-procedure.md`.
7. Add a `docs/data-retention-matrix.md`.
8. Add assistant and Telegram privacy guardrails in tenant settings.
9. Add tests covering sensitive-field exposure and invoice legal fields.
10. Add an admin report/export package for accounting and filing support.

## 6. Suggested owners

- Backend: privacy storage, encryption, audit logs, invoice payloads, exports
- Frontend: settings fields, privacy pages, admin workflows, warnings
- Product/Operations: retention policy, breach process, staff procedures
- Legal/Compliance: final review of privacy, invoicing, tax, and external processors

## 7. Official references reviewed

- National Cyber Governance and Assurance Affairs: Personal Data Privacy library and regulated-entity guidance
- National Cyber Governance and Assurance Affairs: individual rights and complaint guidance
- General Tax Authority: Taxes in Qatar
- General Tax Authority: Laws and Regulations

## 8. Working conclusion

Use this statement internally until the checklist is materially completed:

"The system is configured for Qatar operations, but it should not yet be marketed as fully compliant with Qatar laws and regulations."
