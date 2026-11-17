<?php
declare(strict_types=1);

use Eshcol\Ats\Controllers\AiController;
use Eshcol\Ats\Controllers\AuthController;
use Eshcol\Ats\Controllers\CandidateController;
use Eshcol\Ats\Controllers\ClientController;
use Eshcol\Ats\Controllers\DashboardController;
use Eshcol\Ats\Controllers\MailIntakeController;
use Eshcol\Ats\Controllers\RoleController;
use Eshcol\Ats\Controllers\SettingsController;
use Eshcol\Ats\Controllers\UploadController;

/** @var \Eshcol\Ats\Router $router */

// First-run setup (public, switches itself off once an account exists)
$router->get('/setup', [\Eshcol\Ats\Controllers\SetupController::class, 'show'], public: true);
$router->post('/setup', [\Eshcol\Ats\Controllers\SetupController::class, 'install'], public: true);

// Sign-in (public)
$router->get('/login', [AuthController::class, 'showLogin'], public: true);
$router->post('/login', [AuthController::class, 'login'], public: true);
$router->get('/login/code', [AuthController::class, 'showCode'], public: true);
$router->post('/login/code', [AuthController::class, 'code'], public: true);
$router->get('/login/setup', [AuthController::class, 'showSetup'], public: true);
$router->post('/login/setup', [AuthController::class, 'setup'], public: true);
$router->post('/logout', [AuthController::class, 'logout']);

// Dashboard and the Ctrl+K palette
$router->get('/', [DashboardController::class, 'index']);
$router->get('/search', [\Eshcol\Ats\Controllers\SearchController::class, 'quick']);

// Clients
$router->get('/clients', [ClientController::class, 'index']);
$router->get('/clients/new', [ClientController::class, 'create']);
$router->post('/clients', [ClientController::class, 'store']);
$router->get('/clients/{id}', [ClientController::class, 'show']);
$router->get('/clients/{id}/edit', [ClientController::class, 'edit']);
$router->get('/clients/{id}/logo', [ClientController::class, 'logo']);
$router->post('/clients/{id}', [ClientController::class, 'update']);

// Roles
$router->get('/roles', [RoleController::class, 'index']);
$router->get('/roles/new', [RoleController::class, 'create']);
$router->post('/roles', [RoleController::class, 'store']);
$router->post('/roles/suggest', [RoleController::class, 'suggest']);
$router->post('/roles/read-jd', [RoleController::class, 'readJd']);
$router->get('/roles/{id}', [RoleController::class, 'show']);
$router->get('/roles/{id}/edit', [RoleController::class, 'edit']);
$router->post('/roles/{id}', [RoleController::class, 'update']);
$router->post('/roles/{id}/rescore', [RoleController::class, 'rescore']);
$router->get('/roles/{id}/matches', [RoleController::class, 'matches']);
$router->get('/roles/{id}/post', [\Eshcol\Ats\Controllers\PostingController::class, 'show']);
$router->post('/roles/{id}/add/{cid}', [RoleController::class, 'addCandidate']);
$router->post('/roles/{id}/fit', [\Eshcol\Ats\Controllers\FitController::class, 'save']);
$router->post('/roles/{id}/fit/copy', [\Eshcol\Ats\Controllers\FitController::class, 'copy']);
$router->post('/roles/{id}/fit/suggest',[\Eshcol\Ats\Controllers\FitController::class, 'suggest']);
$router->post('/roles/{id}/fit/queue', [\Eshcol\Ats\Controllers\FitController::class, 'queue']);
$router->post('/roles/{id}/fit/step', [\Eshcol\Ats\Controllers\FitController::class, 'step']);
$router->post('/roles/{id}/examples/client', [\Eshcol\Ats\Controllers\FitController::class, 'examplesFromClient']);
$router->post('/roles/{id}/examples/reassess', [\Eshcol\Ats\Controllers\FitController::class, 'examplesReassess']);
$router->post('/roles/{id}/examples/{cid}', [\Eshcol\Ats\Controllers\FitController::class, 'example']);
$router->post('/roles/{id}/examples/{cid}/note', [\Eshcol\Ats\Controllers\FitController::class, 'exampleNote']);
$router->post('/roles/{id}/rules/suggest', [\Eshcol\Ats\Controllers\FitController::class, 'rulesSuggest']);
$router->post('/roles/{id}/rules/{sid}', [\Eshcol\Ats\Controllers\FitController::class, 'ruleDecide']);
$router->post('/roles/{id}/fair-check', [\Eshcol\Ats\Controllers\FitController::class, 'fairCheck']);
$router->post('/roles/{id}/add-all',[\Eshcol\Ats\Controllers\FitController::class, 'addAll']);
$router->post('/roles/{id}/found', [RoleController::class, 'found']);
$router->get('/roles/{id}/screen', [RoleController::class, 'screen']);
$router->post('/roles/{id}/screen', [RoleController::class, 'screen']);

// Categories (13 Nov 2026): field of work, seniority, where they'd work; the trial and the category test.
$router->get('/settings/categories', [\Eshcol\Ats\Controllers\CategoryController::class, 'settings']);
$router->post('/settings/categories', [\Eshcol\Ats\Controllers\CategoryController::class, 'save']);
$router->post('/settings/categories/queue-all', [\Eshcol\Ats\Controllers\CategoryController::class, 'queueAll']);
$router->post('/settings/categories/step', [\Eshcol\Ats\Controllers\CategoryController::class, 'step']);
$router->post('/settings/categories/trial', [\Eshcol\Ats\Controllers\CategoryController::class, 'trial']);
$router->post('/settings/category-test', [\Eshcol\Ats\Controllers\CategoryController::class, 'testCreate']);
$router->get('/settings/category-test/{id}', [\Eshcol\Ats\Controllers\CategoryController::class, 'testShow']);
$router->post('/settings/category-test/{id}/step', [\Eshcol\Ats\Controllers\CategoryController::class, 'testStep']);
$router->post('/settings/category-test/{id}/delete', [\Eshcol\Ats\Controllers\CategoryController::class, 'testDelete']);
$router->post('/candidates/{id}/category', [\Eshcol\Ats\Controllers\CategoryController::class, 'candidateSave']);
$router->post('/candidates/{id}/category/confirm', [\Eshcol\Ats\Controllers\CategoryController::class, 'candidateConfirm']);
$router->post('/candidates/{id}/category/now', [\Eshcol\Ats\Controllers\CategoryController::class, 'candidateNow']);

// Candidates and applications
$router->get('/candidates', [CandidateController::class, 'index']);
$router->post('/candidates/bulk-add', [CandidateController::class, 'bulkAdd']);
$router->get('/candidates/{id}', [CandidateController::class, 'show']);
$router->get('/candidates/{id}/whatsapp', [CandidateController::class, 'whatsapp']);
$router->get('/candidates/{id}/edit', [CandidateController::class, 'edit']);
$router->post('/candidates/{id}', [CandidateController::class, 'update']);
$router->post('/candidates/{id}/note', [CandidateController::class, 'note']);
$router->post('/candidates/{id}/preferred-cv', [CandidateController::class, 'preferredCv']);
$router->post('/applications/{id}/feedback', [\Eshcol\Ats\Controllers\DecisionController::class, 'feedback']);
$router->post('/applications/{id}/reference', [\Eshcol\Ats\Controllers\DecisionController::class, 'reference']);
$router->post('/applications/{id}/offer', [\Eshcol\Ats\Controllers\DecisionController::class, 'offer']);
$router->post('/applications/{id}/joining', [\Eshcol\Ats\Controllers\DecisionController::class, 'joining']);
$router->post('/candidates/{id}/unreliable', [CandidateController::class, 'unreliable']);
$router->post('/candidates/{id}/roles', [CandidateController::class, 'addToRole']);
$router->post('/candidates/{id}/ai', [AiController::class, 'analyse']);
$router->post('/candidates/{id}/delete', [CandidateController::class, 'destroy']);
$router->post('/candidates/{id}/route', [CandidateController::class, 'route']);
$router->post('/settings/routing', [SettingsController::class, 'saveRouting']);
$router->get('/cv/{id}', [CandidateController::class, 'downloadCv']);
$router->post('/cv/{id}/text', [CandidateController::class, 'saveCvText']);
$router->post('/applications/{id}/stage', [CandidateController::class, 'stage']);
$router->post('/applications/{id}/override', [CandidateController::class, 'override']);

// Screening questionnaire: the candidate's personal link (public), and your side
$router->get('/q/{token}', [\Eshcol\Ats\Controllers\QuestionnaireController::class, 'show'], public: true);
$router->post('/q/{token}', [\Eshcol\Ats\Controllers\QuestionnaireController::class, 'submit'], public: true);
$router->get('/q/{token}/no', [\Eshcol\Ats\Controllers\QuestionnaireController::class, 'decline'], public: true);
$router->post('/q/{token}/no', [\Eshcol\Ats\Controllers\QuestionnaireController::class, 'declineSave'], public: true);
$router->get('/details-requests', [\Eshcol\Ats\Controllers\QuestionnaireController::class, 'askPage']);
$router->post('/details-requests', [\Eshcol\Ats\Controllers\QuestionnaireController::class, 'askSend']);
$router->post('/candidates/{id}/contact', [\Eshcol\Ats\Controllers\QuestionnaireController::class, 'contact']);
$router->post('/messages/{id}/removal', [\Eshcol\Ats\Controllers\QuestionnaireController::class, 'removal']);
$router->get('/messages', [\Eshcol\Ats\Controllers\MessageController::class, 'index']);
$router->post('/applications/{id}/reason', [CandidateController::class, 'reason']);
$router->post('/applications/{id}/review', [CandidateController::class, 'review']);
$router->post('/applications/{id}/fit', [\Eshcol\Ats\Controllers\FitController::class, 'force']);
$router->post('/candidates/{id}/details-reviewed', [CandidateController::class, 'detailsReviewed']);
$router->post('/candidates/{id}/messages', [\Eshcol\Ats\Controllers\MessageController::class, 'send']);
$router->get('/roles/{id}/questions', [\Eshcol\Ats\Controllers\QuestionnaireController::class, 'editor']);
$router->post('/roles/{id}/questions', [\Eshcol\Ats\Controllers\QuestionnaireController::class, 'save']);
$router->post('/roles/{id}/questionnaire', [\Eshcol\Ats\Controllers\QuestionnaireController::class, 'sendRole']);
$router->post('/applications/{id}/questionnaire', [\Eshcol\Ats\Controllers\QuestionnaireController::class, 'sendOne']);

// Client shortlists: your side, and the client's private link (public)
$router->get('/roles/{id}/shortlist/new', [\Eshcol\Ats\Controllers\ShortlistController::class, 'create']);
$router->post('/roles/{id}/shortlist', [\Eshcol\Ats\Controllers\ShortlistController::class, 'store']);
$router->post('/roles/{id}/shortlist/preview', [\Eshcol\Ats\Controllers\ShortlistController::class, 'preview']);
$router->get('/shortlists/{id}', [\Eshcol\Ats\Controllers\ShortlistController::class, 'show']);
$router->get('/shortlists/{id}/pdf', [\Eshcol\Ats\Controllers\ShortlistController::class, 'pdf']);
$router->post('/shortlists/{id}/resend', [\Eshcol\Ats\Controllers\ShortlistController::class, 'resend']);
$router->post('/shortlists/{id}/close', [\Eshcol\Ats\Controllers\ShortlistController::class, 'close']);
$router->get('/shortlists/{id}/email', [\Eshcol\Ats\Controllers\ShortlistController::class, 'emailPreview']);
$router->post('/shortlists/{id}/send', [\Eshcol\Ats\Controllers\ShortlistController::class, 'send']);
$router->post('/shortlists/{id}/discard', [\Eshcol\Ats\Controllers\ShortlistController::class, 'discard']);
$router->get('/shortlists/{id}/items/{item}/plan', [\Eshcol\Ats\Controllers\ShortlistController::class, 'cvPlan']);
$router->get('/shortlists/{id}/items/{item}/cover', [\Eshcol\Ats\Controllers\ShortlistController::class, 'cvCover']);
$router->get('/shortlists/{id}/items/{item}/source', [\Eshcol\Ats\Controllers\ShortlistController::class, 'cvSource']);
$router->post('/shortlists/{id}/items/{item}/branded', [\Eshcol\Ats\Controllers\ShortlistController::class, 'cvBranded']);
$router->post('/shortlists/{id}/items/{item}/render', [\Eshcol\Ats\Controllers\ShortlistController::class, 'cvRender']);
$router->get('/shortlists/{id}/items/{item}/cv', [\Eshcol\Ats\Controllers\ShortlistController::class, 'cvView']);
$router->get('/s/{token}/cv/{item}', [\Eshcol\Ats\Controllers\ShortlistController::class, 'clientCv'], public: true);
$router->get('/s/{token}/zip', [\Eshcol\Ats\Controllers\ShortlistController::class, 'clientZip'], public: true);
$router->get('/s/{token}/logo', [\Eshcol\Ats\Controllers\ShortlistController::class, 'clientLogo'], public: true);
$router->get('/s/{token}', [\Eshcol\Ats\Controllers\ShortlistController::class, 'clientView'], public: true);
$router->post('/s/{token}', [\Eshcol\Ats\Controllers\ShortlistController::class, 'clientSave'], public: true);
$router->get('/s/{token}/pdf', [\Eshcol\Ats\Controllers\ShortlistController::class, 'clientPdf'], public: true);

// Company profiles and JD files
$router->post('/documents', [\Eshcol\Ats\Controllers\DocumentController::class, 'upload']);
$router->get('/documents/{id}', [\Eshcol\Ats\Controllers\DocumentController::class, 'download']);
$router->post('/documents/{id}/delete', [\Eshcol\Ats\Controllers\DocumentController::class, 'delete']);
$router->post('/account/profile', [SettingsController::class, 'saveProfile']);

// Interviews: your side, the candidate's link (public), and the client's suggested times (public)
$router->post('/roles/{id}/slots', [\Eshcol\Ats\Controllers\InterviewController::class, 'addSlots']);
$router->post('/slots/{id}/delete', [\Eshcol\Ats\Controllers\InterviewController::class, 'deleteSlot']);
$router->post('/roles/{id}/interviews/invite', [\Eshcol\Ats\Controllers\InterviewController::class, 'inviteShortlisted']);
$router->post('/applications/{id}/interview/invite', [\Eshcol\Ats\Controllers\InterviewController::class, 'invite']);
$router->post('/applications/{id}/interview/book', [\Eshcol\Ats\Controllers\InterviewController::class, 'book']);
$router->post('/interviews/{id}/outcome', [\Eshcol\Ats\Controllers\InterviewController::class, 'outcome']);
$router->post('/interviews/{id}/cancel', [\Eshcol\Ats\Controllers\InterviewController::class, 'cancel']);
$router->get('/interviews/{id}/letter', [\Eshcol\Ats\Controllers\InterviewController::class, 'letter']);
$router->get('/i/{token}', [\Eshcol\Ats\Controllers\InterviewController::class, 'choose'], public: true);
$router->post('/i/{token}', [\Eshcol\Ats\Controllers\InterviewController::class, 'pick'], public: true);
$router->get('/i/{token}/ics', [\Eshcol\Ats\Controllers\InterviewController::class, 'ics'], public: true);
$router->get('/i/{token}/logo', [\Eshcol\Ats\Controllers\InterviewController::class, 'logo'], public: true);
$router->post('/s/{token}/slots', [\Eshcol\Ats\Controllers\InterviewController::class, 'clientSlots'], public: true);

// Outgoing email
$router->get('/emails', [\Eshcol\Ats\Controllers\EmailController::class, 'index']);
$router->post('/emails/bulk', [\Eshcol\Ats\Controllers\EmailController::class, 'bulk']);
$router->get('/emails/{id}', [\Eshcol\Ats\Controllers\EmailController::class, 'show']);
$router->get('/emails/{id}/body', [\Eshcol\Ats\Controllers\EmailController::class, 'body']);
$router->post('/emails/{id}/release', [\Eshcol\Ats\Controllers\EmailController::class, 'release']);
$router->post('/emails/{id}/cancel', [\Eshcol\Ats\Controllers\EmailController::class, 'cancel']);
$router->post('/emails/{id}/retry', [\Eshcol\Ats\Controllers\EmailController::class, 'retry']);
$router->post('/settings/email', [SettingsController::class, 'saveEmail']);
$router->post('/settings/email/test', [SettingsController::class, 'testEmail']);
$router->post('/settings/email/digest', [SettingsController::class, 'digestNow']);

// Uploads
$router->get('/upload', [UploadController::class, 'page']);
$router->post('/upload/batch', [UploadController::class, 'startBatch']);
$router->post('/upload/file', [UploadController::class, 'file']);

// Settings (owner only, checked in the controller)
$router->get('/settings', [SettingsController::class, 'index']);
$router->post('/settings/stages', [SettingsController::class, 'saveStages']);
$router->post('/settings/skills', [SettingsController::class, 'addSkill']);
$router->post('/settings/users', [SettingsController::class, 'addUser']);
$router->post('/settings/users/done', [SettingsController::class, 'dismissPassword']);
$router->post('/settings/retention', [SettingsController::class, 'saveRetention']);
$router->post('/settings/retention/action', [SettingsController::class, 'retentionAction']);
$router->post('/settings/migrate', [SettingsController::class, 'migrate']);
$router->post('/settings/offsite', [SettingsController::class, 'saveOffsite']);
$router->post('/settings/inbox', [MailIntakeController::class, 'save']);
$router->post('/settings/ai', [AiController::class, 'save']);
$router->post('/settings/inbox/action', [MailIntakeController::class, 'action']);
$router->post('/settings/backup-now', [SettingsController::class, 'backupNow']);
$router->post('/settings/reread', [SettingsController::class, 'reread']);
$router->get('/settings/new-words', [\Eshcol\Ats\Controllers\NewTermsController::class, 'index']);
$router->post('/settings/new-words', [\Eshcol\Ats\Controllers\NewTermsController::class, 'save']);
$router->post('/roles/{id}/wordings', [\Eshcol\Ats\Controllers\NewTermsController::class, 'suggest']);
$router->post('/roles/{id}/wordings/accept', [\Eshcol\Ats\Controllers\NewTermsController::class, 'acceptForRole']);
$router->get('/settings/staffing', [\Eshcol\Ats\Controllers\StaffingController::class, 'index']);
$router->post('/settings/staffing/rates', [\Eshcol\Ats\Controllers\StaffingController::class, 'saveRates']);
$router->post('/settings/staffing/wages', [\Eshcol\Ats\Controllers\StaffingController::class, 'addWage']);
$router->post('/settings/staffing/wages/confirm', [\Eshcol\Ats\Controllers\StaffingController::class, 'confirmWages']);
$router->post('/settings/staffing/da', [\Eshcol\Ats\Controllers\StaffingController::class, 'addDa']);
$router->post('/settings/staffing/wages/{id}/delete', [\Eshcol\Ats\Controllers\StaffingController::class, 'deleteWage']);
$router->post('/settings/staffing/licence', [\Eshcol\Ats\Controllers\StaffingController::class, 'saveLicence']);
$router->post('/clients/{id}/staffing-terms', [\Eshcol\Ats\Controllers\StaffingController::class, 'saveTerms']);
$router->get('/settings/ai-reading', [\Eshcol\Ats\Controllers\AiReadingController::class, 'index']);
$router->post('/settings/ai-reading', [\Eshcol\Ats\Controllers\AiReadingController::class, 'save']);
$router->post('/settings/ai-reading/queue-all', [\Eshcol\Ats\Controllers\AiReadingController::class, 'queueAll']);
$router->post('/settings/ai-reading/step', [\Eshcol\Ats\Controllers\AiReadingController::class, 'step']);
$router->post('/settings/ai-reading/apply', [\Eshcol\Ats\Controllers\AiReadingController::class, 'apply']);
$router->post('/settings/ai-reading/retry', [\Eshcol\Ats\Controllers\AiReadingController::class, 'retry']);
$router->get('/settings/reader-test',[\Eshcol\Ats\Controllers\ReaderTestController::class, 'index']);
$router->post('/settings/reader-test', [\Eshcol\Ats\Controllers\ReaderTestController::class, 'create']);
$router->get('/settings/reader-test/{id}', [\Eshcol\Ats\Controllers\ReaderTestController::class, 'show']);
$router->post('/settings/reader-test/{id}/run', [\Eshcol\Ats\Controllers\ReaderTestController::class, 'run']);
$router->post('/settings/reader-test/{id}/delete', [\Eshcol\Ats\Controllers\ReaderTestController::class, 'delete']);
$router->get('/settings/reader-test/{id}/cv/{cid}', [\Eshcol\Ats\Controllers\ReaderTestController::class, 'review']);
$router->post('/settings/reader-test/{id}/cv/{cid}', [\Eshcol\Ats\Controllers\ReaderTestController::class, 'saveTruth']);

// Your own account (any signed-in user)
$router->get('/account', [SettingsController::class, 'account']);
$router->post('/account/password', [SettingsController::class, 'changePassword']);
$router->post('/account/devices/forget', [SettingsController::class, 'forgetDevices']);
$router->post('/account/email', [SettingsController::class, 'changeEmail']);

// The client portal (13 Nov 2026): a client's contact signs in with an emailed link. Each route checks that sign-in itself.
$router->get('/portal', [\Eshcol\Ats\Controllers\PortalController::class, 'home'], public: true);
$router->post('/portal/link', [\Eshcol\Ats\Controllers\PortalController::class, 'link'], public: true);
$router->get('/portal/in/{token}', [\Eshcol\Ats\Controllers\PortalController::class, 'open'], public: true);
$router->post('/portal/in/{token}', [\Eshcol\Ats\Controllers\PortalController::class, 'enter'], public: true);
$router->post('/portal/out', [\Eshcol\Ats\Controllers\PortalController::class, 'out'], public: true);
$router->get('/portal/logo', [\Eshcol\Ats\Controllers\PortalController::class, 'logo'], public: true);
$router->get('/portal/new', [\Eshcol\Ats\Controllers\PortalController::class, 'newForm'], public: true);
$router->post('/portal/new', [\Eshcol\Ats\Controllers\PortalController::class, 'newSave'], public: true);
$router->get('/portal/roles/{id}', [\Eshcol\Ats\Controllers\PortalController::class, 'role'], public: true);
$router->post('/portal/roles/{id}', [\Eshcol\Ats\Controllers\PortalController::class, 'decide'], public: true);
$router->get('/portal/roles/{id}/cv/{item}', [\Eshcol\Ats\Controllers\PortalController::class, 'cv'], public: true);
$router->post('/portal/roles/{id}/times', [\Eshcol\Ats\Controllers\PortalController::class, 'times'], public: true);
$router->post('/portal/slots/{slot}/delete', [\Eshcol\Ats\Controllers\PortalController::class, 'removeSlot'], public: true);
// Giving a client's contact portal access (the client's page).
$router->post('/clients/{id}/contacts', [ClientController::class, 'addContact']);
$router->post('/clients/{id}/contacts/{cid}/portal', [ClientController::class, 'portalAccess']);

// Bounces, consent and scheduled jobs (14 Nov 2026).
$router->post('/hooks/brevo/{token}', [\Eshcol\Ats\Controllers\HealthController::class, 'brevoHook'], public: true);
$router->post('/settings/email/brevo-hook', [\Eshcol\Ats\Controllers\HealthController::class, 'connectBrevo']);
$router->post('/candidates/{id}/email-ok', [\Eshcol\Ats\Controllers\HealthController::class, 'clearEmail']);
$router->get('/candidates/{id}/data', [\Eshcol\Ats\Controllers\HealthController::class, 'exportData']);
// Contractors (B6, 14 Nov 2026): their positions; switching an account off.
$router->post('/settings/users/{id}/positions', [SettingsController::class, 'userPositions']);
$router->post('/settings/users/{id}/active', [SettingsController::class, 'userActive']);
// "Share your updated CV" (14 Nov 2026): the candidate's personal page, and your page to start and follow it.
$router->get('/u/{token}', [\Eshcol\Ats\Controllers\CvRequestController::class, 'show'], public: true);
$router->post('/u/{token}', [\Eshcol\Ats\Controllers\CvRequestController::class, 'upload'], public: true);
$router->get('/u/{token}/keep', [\Eshcol\Ats\Controllers\CvRequestController::class, 'keepPage'], public: true);
$router->post('/u/{token}/keep', [\Eshcol\Ats\Controllers\CvRequestController::class, 'keep'], public: true);
$router->get('/u/{token}/remove', [\Eshcol\Ats\Controllers\CvRequestController::class, 'removePage'], public: true);
$router->post('/u/{token}/remove', [\Eshcol\Ats\Controllers\CvRequestController::class, 'remove'], public: true);
$router->get('/cv-requests', [\Eshcol\Ats\Controllers\CvRequestController::class, 'index']);
$router->post('/cv-requests/start', [\Eshcol\Ats\Controllers\CvRequestController::class, 'start']);
$router->post('/cv-requests/settings', [\Eshcol\Ats\Controllers\CvRequestController::class, 'settings']);
// Placements (B9, 14 Nov 2026): the owner's only.
$router->get('/placements', [\Eshcol\Ats\Controllers\PlacementController::class, 'index']);
$router->get('/joinings', [\Eshcol\Ats\Controllers\JoiningController::class, 'index']);
$router->get('/joinings/download.csv', [\Eshcol\Ats\Controllers\JoiningController::class, 'csv']);
$router->get('/joinings/{id}/json', [\Eshcol\Ats\Controllers\JoiningController::class, 'json']);
$router->post('/joinings/{id}/status', [\Eshcol\Ats\Controllers\JoiningController::class, 'status']);
$router->post('/joinings/{id}/refresh', [\Eshcol\Ats\Controllers\JoiningController::class, 'refresh']);
$router->post('/placements/{id}', [\Eshcol\Ats\Controllers\PlacementController::class, 'save']);
$router->post('/placements/{id}/checkin/{day}', [\Eshcol\Ats\Controllers\PlacementController::class, 'checkin']);
$router->post('/placements/{id}/left', [\Eshcol\Ats\Controllers\PlacementController::class, 'left']);
$router->post('/settings/ai-reading/names', [\Eshcol\Ats\Controllers\AiReadingController::class, 'names']);
// One-click unsubscribe from bulk emails (14 Nov 2026). The POST comes from the mail provider, so no form token.
$router->get('/unsub/{token}', [\Eshcol\Ats\Controllers\HealthController::class, 'unsubPage'], public: true);
$router->post('/unsub/{token}', [\Eshcol\Ats\Controllers\HealthController::class, 'unsub'], public: true);
// Checks before a shortlist goes (B11, 14 Nov 2026): permission after a call; chasing the client.
$router->post('/applications/{id}/permission', [CandidateController::class, 'permission']);
$router->post('/shortlists/{id}/chased', [\Eshcol\Ats\Controllers\ShortlistController::class, 'chased']);
// Saved views on the candidate list (B15, 14 Nov 2026).
$router->post('/candidates/views', [CandidateController::class, 'saveView']);
$router->post('/candidates/views/{id}/delete', [CandidateController::class, 'deleteView']);
$router->get('/applications/{id}/why', [CandidateController::class, 'why']); // "Why this score", loaded when opened (B12)
$router->get('/reports', [\Eshcol\Ats\Controllers\ReportController::class, 'index']); // B10, owner only

// The interview calendar (17 Nov 2026).
$router->get('/calendar', [\Eshcol\Ats\Controllers\CalendarController::class, 'index']);
$router->post('/calendar/allot', [\Eshcol\Ats\Controllers\CalendarController::class, 'allot']);
$router->post('/calendar/times', [\Eshcol\Ats\Controllers\CalendarController::class, 'addTimes']);
$router->post('/calendar/remove', [\Eshcol\Ats\Controllers\CalendarController::class, 'removeTime']);
