<?php

use App\Http\Controllers\ActiveLoanMigrationController;
use App\Http\Controllers\AdminProfileController;
use App\Http\Controllers\AgmMeetingController;
use App\Http\Controllers\AssetRegisterController;
use App\Http\Controllers\BankReconciliationController;
use App\Http\Controllers\BoardRoleController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\BudgetImportController;
use App\Http\Controllers\CashBookController;
use App\Http\Controllers\ChartAccountManagementController;
use App\Http\Controllers\ChartAccountTransferController;
use App\Http\Controllers\ChartOfAccountsController;
use App\Http\Controllers\CutoverChecklistController;
use App\Http\Controllers\DataImportController;
use App\Http\Controllers\ExitSettlementRateController;
use App\Http\Controllers\GeneralJournalController;
use App\Http\Controllers\LedgerFinancialReportsController;
use App\Http\Controllers\LedgerJournalReversalController;
use App\Http\Controllers\LedgerOpeningJournalController;
use App\Http\Controllers\LedgerPeriodController;
use App\Http\Controllers\LegacyLoanController;
use App\Http\Controllers\LegacyMemberTransactionsController;
use App\Http\Controllers\LoanApplicationController;
use App\Http\Controllers\LoanBatchController;
use App\Http\Controllers\LoanCalculatorController;
use App\Http\Controllers\LoanCategoryController;
use App\Http\Controllers\LoanJournalController;
use App\Http\Controllers\LoanLedgerReportController;
use App\Http\Controllers\LoanPricingController;
use App\Http\Controllers\LoanRegisterController;
use App\Http\Controllers\LoanSponsorshipController;
use App\Http\Controllers\LoanTransactionsReportController;
use App\Http\Controllers\MailDeliverySettingsController;
use App\Http\Controllers\MailingAccountController;
use App\Http\Controllers\MemberAccountController;
use App\Http\Controllers\MemberAgmController;
use App\Http\Controllers\MemberExitController;
use App\Http\Controllers\MemberExitPostingController;
use App\Http\Controllers\MemberFundJournalController;
use App\Http\Controllers\MemberFundsController;
use App\Http\Controllers\MemberLoginController;
use App\Http\Controllers\MemberNextOfKinController;
use App\Http\Controllers\MemberNotificationController;
use App\Http\Controllers\MemberProfileController;
use App\Http\Controllers\MemberSavingsController;
use App\Http\Controllers\MembershipControlsController;
use App\Http\Controllers\MonthlyStatementDraftController;
use App\Http\Controllers\MonthlyStatementReviewController;
use App\Http\Controllers\PayrollCorrectionController;
use App\Http\Controllers\PayrollDeductionController;
use App\Http\Controllers\PayrollJournalController;
use App\Http\Controllers\PayrollLoanReconciliationController;
use App\Http\Controllers\SavingsLoanPaymentController;
use App\Http\Controllers\StaffAdminController;
use App\Http\Controllers\StaffPasswordSetupController;
use App\Http\Controllers\StaffSessionController;
use App\Http\Controllers\TaskInboxController;
use App\Http\Controllers\VerifiedBalanceImportController;
use App\Http\Controllers\VerifiedLegacyLoanBalanceController;
use Illuminate\Support\Facades\Route;

Route::get('/', LoanCalculatorController::class)->name('loan.calculator');
Route::get('/loan-calculator', LoanCalculatorController::class)->name('loan.schedule');

Route::get('/staff/login', [StaffSessionController::class, 'show'])->middleware('guest')->name('login');
Route::post('/staff/login', [StaffSessionController::class, 'store'])->middleware(['guest', 'throttle:5,1'])->name('staff.login');
Route::get('/staff/password-setup', [StaffPasswordSetupController::class, 'show'])
    ->middleware(['guest', 'throttle:15,1'])->name('staff.password-setup');
Route::post('/staff/password-setup', [StaffPasswordSetupController::class, 'store'])
    ->middleware(['guest', 'throttle:5,1'])->name('staff.password-setup.store');
Route::post('/staff/logout', [StaffSessionController::class, 'destroy'])->middleware('auth')->name('staff.logout');

Route::post('/member/loan-sponsorships/{nomination}/confirm', [LoanSponsorshipController::class, 'confirm'])
    ->whereNumber('nomination')->middleware(['auth', 'auth.session'])->name('member.loan-sponsorships.confirm');
Route::get('/member/account', MemberAccountController::class)->middleware(['auth', 'auth.session'])->name('member.account');

Route::prefix('member')->name('member.')->middleware(['auth', 'auth.session'])->group(function (): void {
    Route::get('/loan-sponsors', [LoanSponsorshipController::class, 'search'])->middleware('throttle:60,1')->name('loan-sponsors.search');
    Route::get('/notifications', [MemberNotificationController::class, 'index'])->name('notifications.index');
    Route::get('/agm', [MemberAgmController::class, 'index'])->name('agm.index');
    Route::post('/agm/{meeting}/register', [MemberAgmController::class, 'register'])->whereNumber('meeting')->name('agm.register');
    Route::get('/notifications/count', [MemberNotificationController::class, 'count'])->name('notifications.count');
    Route::get('/loan-applications/new', [LoanApplicationController::class, 'create'])->name('loan-applications.create');
    Route::post('/loan-applications', [LoanApplicationController::class, 'store'])->name('loan-applications.store');
});

Route::post('/member/savings-withdrawals', [MemberFundsController::class, 'requestWithdrawal'])->middleware(['auth', 'auth.session'])->name('member.savings-withdrawal');
Route::post('/member/exit', [MemberExitController::class, 'storeOwn'])->middleware(['auth', 'auth.session'])->name('member.exit');

Route::get('/branding/logo/{revision}', [BrandingController::class, 'logo'])->whereNumber('revision')->name('branding.logo');

Route::prefix('staff')->name('staff.')->middleware(['auth', 'auth.session', 'can:manage-members'])->group(function (): void {
    Route::prefix('agm')->name('agm.')->group(function (): void {
        Route::get('/', [AgmMeetingController::class, 'index'])->name('index');
        Route::post('/', [AgmMeetingController::class, 'store'])->name('store');
        Route::get('/{meeting}', [AgmMeetingController::class, 'show'])->whereNumber('meeting')->name('show');
        Route::patch('/{meeting}/registration', [AgmMeetingController::class, 'updateRegistration'])->whereNumber('meeting')->name('registration.update');
        Route::patch('/{meeting}/registrations/{registration}', [AgmMeetingController::class, 'checkIn'])->whereNumber(['meeting', 'registration'])->name('check-in');
        Route::get('/{meeting}/register.csv', [AgmMeetingController::class, 'export'])->whereNumber('meeting')->name('export');
    });
    Route::get('/mailing-accounts', [MailingAccountController::class, 'index'])->name('mailing-accounts.index');
    Route::post('/mailing-accounts', [MailingAccountController::class, 'store'])->name('mailing-accounts.store');
    Route::patch('/mailing-accounts/selection', [MailingAccountController::class, 'select'])->name('mailing-accounts.select');
    Route::patch('/mailing-accounts/incoming', [MailDeliverySettingsController::class, 'incoming'])->name('mailing-accounts.incoming');
    Route::patch('/mailing-accounts/automatic', [MailDeliverySettingsController::class, 'automatic'])->name('mailing-accounts.automatic');
    Route::get('/branding', [BrandingController::class, 'edit'])->name('branding.edit');
    Route::patch('/branding', [BrandingController::class, 'update'])->name('branding.update');
    Route::get('/exit-settlement-rates', [ExitSettlementRateController::class, 'edit'])->name('exit-settlement-rates.edit');
    Route::patch('/exit-settlement-rates', [ExitSettlementRateController::class, 'update'])->name('exit-settlement-rates.update');
    Route::get('/member-savings', MemberSavingsController::class)->name('member-savings.index');
    Route::get('/members/{member}/funds', [MemberFundsController::class, 'index'])->whereNumber('member')->name('member-funds.index');
    Route::post('/members/{member}/funds', [MemberFundsController::class, 'deposit'])->whereNumber('member')->name('member-funds.deposit');
    Route::post('/members/{member}/funds/deposit-interest', [MemberFundsController::class, 'prepareDepositInterest'])->whereNumber('member')->name('member-funds.deposit-interest');
    Route::post('/members/{member}/funds/savings-interest', [MemberFundsController::class, 'prepareSavingsInterest'])->whereNumber('member')->name('member-funds.savings-interest');
    Route::post('/members/{member}/loan-applications/{application}/savings-payment', SavingsLoanPaymentController::class)
        ->whereNumber(['member', 'application'])->name('loan-applications.savings-payment');
    Route::get('/membership-minimums', [MembershipControlsController::class, 'minimums'])->name('membership-minimums.index');
    Route::patch('/membership-minimums', [MembershipControlsController::class, 'saveMinimums'])->name('membership-minimums.update');
    Route::get('/cutover-checklist', CutoverChecklistController::class)->name('cutover-checklist');
    Route::get('/data-imports', [DataImportController::class, 'index'])->name('data-imports.index');
    Route::get('/payroll-corrections', [PayrollCorrectionController::class, 'index'])->name('payroll-corrections.index');
    Route::patch('/payroll-corrections/{correction}/resolve', [PayrollCorrectionController::class, 'resolve'])
        ->whereNumber('correction')->name('payroll-corrections.resolve');
    Route::get('/payroll-loan-reconciliation', [PayrollLoanReconciliationController::class, 'index'])->name('payroll-loan-reconciliation.index');
    Route::patch('/payroll-loan-reconciliation/{deduction}', [PayrollLoanReconciliationController::class, 'reconcile'])
        ->whereNumber('deduction')->name('payroll-loan-reconciliation.reconcile');
    Route::get('/data-imports/templates/{kind}', [DataImportController::class, 'template'])->name('data-imports.template');
    Route::post('/data-imports/preview', [DataImportController::class, 'preview'])->name('data-imports.preview');
    Route::post('/data-imports/apply', [DataImportController::class, 'apply'])->name('data-imports.apply');
    Route::get('/verified-balance-import', [VerifiedBalanceImportController::class, 'index'])->name('verified-balance-import.index');
    Route::get('/verified-balance-import/template', [VerifiedBalanceImportController::class, 'template'])->name('verified-balance-import.template');
    Route::post('/verified-balance-import/preview', [VerifiedBalanceImportController::class, 'preview'])->name('verified-balance-import.preview');
    Route::post('/verified-balance-import/apply', [VerifiedBalanceImportController::class, 'apply'])->name('verified-balance-import.apply');
    Route::get('/members/{member}/exit', [MemberExitController::class, 'create'])->whereNumber('member')->name('member-exits.create');
    Route::post('/members/{member}/exit', [MemberExitController::class, 'store'])->whereNumber('member')->name('member-exits.store');
    Route::get('/board-roles', [BoardRoleController::class, 'index'])->name('board-roles.index');
    Route::patch('/board-roles/{role}', [BoardRoleController::class, 'update'])->whereIn('role', ['chairperson', 'secretary'])->name('board-roles.update');
    Route::get('/member-fund-journals', [MemberFundJournalController::class, 'index'])->name('member-fund-journals.index');
    Route::get('/member-fund-journals/{transaction}', [MemberFundJournalController::class, 'show'])->whereNumber('transaction')->name('member-fund-journals.show');
    Route::post('/member-fund-journals/{transaction}/preview', [MemberFundJournalController::class, 'preview'])->whereNumber('transaction')->name('member-fund-journals.preview');
    Route::post('/member-fund-journals/{transaction}/post', [MemberFundJournalController::class, 'post'])->whereNumber('transaction')->name('member-fund-journals.post');
    Route::get('/bank-reconciliation', [BankReconciliationController::class, 'index'])->name('bank-reconciliation.index');
    Route::post('/bank-reconciliation', [BankReconciliationController::class, 'store'])->name('bank-reconciliation.store');
    Route::get('/cash-book', [CashBookController::class, 'index'])->name('cash-book.index');
    Route::get('/cash-book/export', [CashBookController::class, 'export'])->name('cash-book.export');
    Route::get('/cash-book/transactions/new', [GeneralJournalController::class, 'create'])->name('cash-book.transactions.create');
    Route::post('/cash-book/transactions/preview', [GeneralJournalController::class, 'preview'])->name('cash-book.transactions.preview');
    Route::post('/cash-book/transactions/post', [GeneralJournalController::class, 'post'])->name('cash-book.transactions.post');
    Route::get('/ledger-financial-reports', LedgerFinancialReportsController::class)->name('ledger-financial-reports');
    Route::get('/assets', [AssetRegisterController::class, 'index'])->name('assets.index');
    Route::post('/assets', [AssetRegisterController::class, 'store'])->name('assets.store');
    Route::patch('/assets/{asset}', [AssetRegisterController::class, 'update'])->whereNumber('asset')->name('assets.update');
    Route::get('/budgets', [BudgetController::class, 'index'])->name('budgets.index');
    Route::post('/budgets', [BudgetController::class, 'store'])->name('budgets.store');
    Route::get('/budgets/import/template', [BudgetImportController::class, 'template'])->name('budgets.import.template');
    Route::post('/budgets/import/preview', [BudgetImportController::class, 'preview'])->name('budgets.import.preview');
    Route::post('/budgets/import/apply', [BudgetImportController::class, 'apply'])->name('budgets.import.apply');
    Route::get('/ledger-opening', [LedgerOpeningJournalController::class, 'index'])->name('ledger-opening.index');
    Route::get('/ledger-opening/template', [LedgerOpeningJournalController::class, 'template'])->name('ledger-opening.template');
    Route::post('/ledger-opening/preview', [LedgerOpeningJournalController::class, 'preview'])->name('ledger-opening.preview');
    Route::post('/ledger-opening/post', [LedgerOpeningJournalController::class, 'post'])->name('ledger-opening.post');
    Route::get('/ledger-periods', [LedgerPeriodController::class, 'index'])->name('ledger-periods.index');
    Route::post('/ledger-periods', [LedgerPeriodController::class, 'record'])->name('ledger-periods.record');
    Route::get('/general-journals', [GeneralJournalController::class, 'index'])->name('general-journals.index');
    Route::get('/general-journals/new', [GeneralJournalController::class, 'create'])->name('general-journals.create');
    Route::post('/general-journals/preview', [GeneralJournalController::class, 'preview'])->name('general-journals.preview');
    Route::post('/general-journals/post', [GeneralJournalController::class, 'post'])->name('general-journals.post');
    Route::get('/general-journals/{journal}', [GeneralJournalController::class, 'show'])->whereNumber('journal')->name('general-journals.show');
    Route::get('/general-journals/{journal}/voucher', [GeneralJournalController::class, 'voucher'])->whereNumber('journal')->name('general-journals.voucher');
    Route::get('/ledger-reversals', [LedgerJournalReversalController::class, 'index'])->name('ledger-reversals.index');
    Route::get('/ledger-reversals/{reversal}', [LedgerJournalReversalController::class, 'show'])->whereNumber('reversal')->name('ledger-reversals.show');
    Route::get('/ledger-reversals/{source}/{journal}', [LedgerJournalReversalController::class, 'preview'])
        ->whereIn('source', ['loan', 'member-fund', 'general'])->whereNumber('journal')->name('ledger-reversals.preview');
    Route::post('/ledger-reversals/{source}/{journal}', [LedgerJournalReversalController::class, 'store'])
        ->whereIn('source', ['loan', 'member-fund', 'general'])->whereNumber('journal')->name('ledger-reversals.store');
    Route::get('/loan-ledger', [LoanLedgerReportController::class, 'index'])->name('loan-ledger.index');
    Route::get('/loan-ledger/accounts/{account}', [LoanLedgerReportController::class, 'account'])->whereNumber('account')->name('loan-ledger.account');
    Route::get('/loan-batches/{batch}/deductions', [PayrollDeductionController::class, 'show'])->whereNumber('batch')->name('payroll-deductions.show');
    Route::get('/loan-batches/{batch}/deductions/template', [PayrollDeductionController::class, 'template'])->whereNumber('batch')->name('payroll-deductions.template');
    Route::post('/loan-batches/{batch}/deductions/preview', [PayrollDeductionController::class, 'preview'])->whereNumber('batch')->name('payroll-deductions.preview');
    Route::get('/loan-batches/{batch}/journals', [PayrollJournalController::class, 'show'])->whereNumber('batch')->name('payroll-journals.show');
    Route::post('/loan-batches/{batch}/journals/preview', [PayrollJournalController::class, 'preview'])->whereNumber('batch')->name('payroll-journals.preview');
    Route::post('/loan-batches/{batch}/journals/post', [PayrollJournalController::class, 'post'])->whereNumber('batch')->name('payroll-journals.post');

    Route::get('/loan-journal', [LoanJournalController::class, 'index'])->name('loan-journal.index');
    Route::get('/loan-journal/{batch}', [LoanJournalController::class, 'show'])->whereNumber('batch')->name('loan-journal.show');
    Route::post('/loan-journal/{batch}/preview', [LoanJournalController::class, 'preview'])->whereNumber('batch')->name('loan-journal.preview');
    Route::post('/loan-journal/{batch}/post', [LoanJournalController::class, 'post'])->whereNumber('batch')->name('loan-journal.post');
    Route::get('/statement-review', [MonthlyStatementReviewController::class, 'index'])->name('statement-review.index');
    Route::get('/statement-review/sample', [MonthlyStatementReviewController::class, 'sample'])->name('statement-review.sample');
    Route::post('/statement-review/preview', [MonthlyStatementReviewController::class, 'preview'])->name('statement-review.preview');
    Route::post('/statement-review/preview-drafts', [MonthlyStatementReviewController::class, 'previewDrafts'])->name('statement-review.preview-drafts');
    Route::post('/statement-review/queue', [MonthlyStatementReviewController::class, 'queue'])->name('statement-review.queue');
    Route::get('/statement-drafts', [MonthlyStatementDraftController::class, 'index'])->name('statement-drafts.index');
    Route::get('/statement-drafts/download', [MonthlyStatementDraftController::class, 'download'])->name('statement-drafts.download');
    Route::get('/loan-transactions', LoanTransactionsReportController::class)->name('loan-transactions');
    Route::get('/accounts', ChartOfAccountsController::class)->name('accounts.index');
    Route::get('/accounts/new', [ChartAccountManagementController::class, 'create'])->name('chart-accounts.create');
    Route::post('/accounts', [ChartAccountManagementController::class, 'store'])->name('chart-accounts.store');
    Route::get('/accounts/{account}', [ChartAccountManagementController::class, 'show'])->whereNumber('account')->name('chart-accounts.show');
    Route::patch('/accounts/{account}/cash-book', [ChartAccountManagementController::class, 'updateCashBook'])->whereNumber('account')->name('chart-accounts.cash-book');
    Route::get('/accounts/{account}/replacement', [ChartAccountManagementController::class, 'replacement'])->whereNumber('account')->name('chart-accounts.replacement');
    Route::post('/accounts/{account}/replacement', [ChartAccountManagementController::class, 'replace'])->whereNumber('account')->name('chart-accounts.replace');
    Route::get('/accounts/{account}/balance-transfer', [ChartAccountTransferController::class, 'index'])->whereNumber('account')->name('chart-accounts.transfer');
    Route::post('/accounts/{account}/balance-transfer/preview', [ChartAccountTransferController::class, 'preview'])->whereNumber('account')->name('chart-accounts.transfer-preview');
    Route::post('/accounts/{account}/balance-transfer/post', [ChartAccountTransferController::class, 'post'])->whereNumber('account')->name('chart-accounts.transfer-post');
    Route::get('/account-transfers/{transfer}', [ChartAccountTransferController::class, 'show'])->whereNumber('transfer')->name('chart-account-transfers.show');
    Route::post('/accounts/{account}/retire', [ChartAccountManagementController::class, 'retire'])->whereNumber('account')->name('chart-accounts.retire');
    Route::get('/active-loans/migrate', [ActiveLoanMigrationController::class, 'index'])->name('active-loans.index');
    Route::get('/active-loans/migrate/template', [ActiveLoanMigrationController::class, 'template'])->name('active-loans.template');
    Route::post('/active-loans/migrate/preview', [ActiveLoanMigrationController::class, 'preview'])->name('active-loans.preview');
    Route::post('/active-loans/migrate/apply', [ActiveLoanMigrationController::class, 'apply'])->name('active-loans.apply');
    Route::get('/verified-legacy-loans/template', [VerifiedLegacyLoanBalanceController::class, 'template'])->name('verified-legacy-loans.template');
    Route::get('/verified-legacy-loans', [VerifiedLegacyLoanBalanceController::class, 'index'])->name('verified-legacy-loans.index');
    Route::post('/verified-legacy-loans/preview', [VerifiedLegacyLoanBalanceController::class, 'preview'])->name('verified-legacy-loans.preview');
    Route::post('/verified-legacy-loans/apply', [VerifiedLegacyLoanBalanceController::class, 'apply'])->name('verified-legacy-loans.apply');
    Route::get('/loan-categories', [LoanCategoryController::class, 'index'])->name('loan-categories.index');
    Route::post('/loan-categories', [LoanCategoryController::class, 'store'])->name('loan-categories.store');
    Route::patch('/loan-categories/{category}', [LoanCategoryController::class, 'update'])->whereNumber('category')->name('loan-categories.update');
    Route::get('/loan-pricing', [LoanPricingController::class, 'edit'])->name('loan-pricing.edit');
    Route::patch('/loan-pricing', [LoanPricingController::class, 'update'])->name('loan-pricing.update');
    Route::get('/admin-accounts', [StaffAdminController::class, 'index'])->name('admin-accounts.index');
    Route::post('/admin-accounts', [StaffAdminController::class, 'store'])->name('admin-accounts.store');
    Route::get('/members', [MemberProfileController::class, 'index'])->name('members.index');
    Route::get('/members/new', [MemberProfileController::class, 'create'])->name('members.create');
    Route::post('/members', [MemberProfileController::class, 'store'])->name('members.store');
    Route::get('/members/{member}/next-of-kin', [MemberNextOfKinController::class, 'index'])->whereNumber('member')->name('members.next-of-kin.index');
    Route::post('/members/{member}/next-of-kin', [MemberNextOfKinController::class, 'store'])->whereNumber('member')->name('members.next-of-kin.store');
    Route::patch('/members/{member}/next-of-kin/{contact}', [MemberNextOfKinController::class, 'update'])->whereNumber(['member', 'contact'])->name('members.next-of-kin.update');
    Route::get('/members/{member}', [MemberProfileController::class, 'show'])->whereNumber('member')->name('members.show');
    Route::post('/members/{member}/login-link', MemberLoginController::class)->whereNumber('member')->middleware('throttle:15,1')->name('members.login-link');
    Route::get('/members/{member}/legacy-savings', LegacyMemberTransactionsController::class)->whereNumber('member')->name('legacy-member-transactions');
    Route::get('/members/{member}/legacy-loans', [LegacyLoanController::class, 'index'])->whereNumber('member')->name('legacy-loans.index');
    Route::get('/members/{member}/legacy-loans/{loan}', [LegacyLoanController::class, 'show'])->whereNumber(['member', 'loan'])->name('legacy-loans.show');
    Route::prefix('members/{member}/loan-applications')->where(['member' => '[0-9]+'])->name('loan-applications.')->group(function (): void {
        Route::get('/', [LoanApplicationController::class, 'index'])->name('index');
    });
    Route::patch('/members/{member}', [MemberProfileController::class, 'update'])->whereNumber('member')->name('members.update');
    Route::patch('/members/{member}/status', [MemberProfileController::class, 'updateStatus'])->whereNumber('member')->name('members.status.update');
    Route::patch('/members/{member}/loan-role', [MemberProfileController::class, 'updateLoanRole'])->whereNumber('member')->name('members.loan-role.update');
    Route::patch('/members/{member}/payroll-contributions', [MemberProfileController::class, 'updatePayroll'])->whereNumber('member')->name('members.payroll.update');
});

Route::prefix('staff')->name('staff.')->middleware(['auth', 'auth.session', 'can:access-loan-workflow'])->group(function (): void {
    Route::get('/profile', [AdminProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [AdminProfileController::class, 'update'])->name('profile.update');
    Route::get('/savings-withdrawals', [MemberFundsController::class, 'withdrawals'])->name('savings-withdrawals.index');
    Route::get('/savings-withdrawals/{withdrawal}', [MemberFundsController::class, 'showWithdrawal'])->whereNumber('withdrawal')->name('savings-withdrawals.show');
    Route::post('/savings-withdrawals/{withdrawal}/review', [MemberFundsController::class, 'review'])->whereNumber('withdrawal')->middleware('can:review-loans')->name('savings-withdrawals.review');
    Route::post('/savings-withdrawals/{withdrawal}/pay', [MemberFundsController::class, 'pay'])->whereNumber('withdrawal')->middleware('can:manage-members')->name('savings-withdrawals.pay');
    Route::get('/member-exits', [MemberExitController::class, 'index'])->name('member-exits.index');
    Route::get('/member-exits/{exit}', [MemberExitController::class, 'show'])->whereNumber('exit')->name('member-exits.show');
    Route::get('/member-exits/{exit}/voucher', [MemberExitController::class, 'voucher'])->whereNumber('exit')->name('member-exits.voucher');
    Route::post('/member-exits/{exit}/review', [MemberExitController::class, 'review'])->whereNumber('exit')->middleware('can:review-loans')->name('member-exits.review');
    Route::post('/member-exits/{exit}/finalize', [MemberExitController::class, 'finalize'])->whereNumber('exit')->middleware('can:manage-members')->name('member-exits.finalize');
    Route::get('/member-exits/{exit}/settlement', [MemberExitPostingController::class, 'show'])
        ->whereNumber('exit')->middleware('can:manage-members')->name('member-exits.settlement.show');
    Route::post('/member-exits/{exit}/settlement', [MemberExitPostingController::class, 'post'])
        ->whereNumber('exit')->middleware('can:manage-members')->name('member-exits.settlement.post');
    Route::get('/loan-register', LoanRegisterController::class)->name('loan-register');
    Route::prefix('loan-batches')->name('loan-batches.')->group(function (): void {
        Route::get('/', [LoanBatchController::class, 'index'])->name('index');
        Route::get('/new', [LoanBatchController::class, 'create'])->middleware('can:manage-members')->name('create');
        Route::post('/', [LoanBatchController::class, 'store'])->middleware('can:manage-members')->name('store');
        Route::get('/{batch}', [LoanBatchController::class, 'show'])->whereNumber('batch')->name('show');
        Route::post('/{batch}/review', [LoanBatchController::class, 'review'])->whereNumber('batch')->middleware('can:review-loans')->name('review');
        Route::post('/{batch}/confirm', [LoanBatchController::class, 'confirm'])->whereNumber('batch')->middleware('can:manage-members')->name('confirm');
        Route::get('/{batch}/checklist', [LoanBatchController::class, 'checklist'])->whereNumber('batch')->name('checklist');
    });
});

Route::prefix('staff')->name('staff.')->middleware(['auth', 'auth.session', 'can:submit-loans'])->group(function (): void {
    Route::get('/members/{member}/loan-sponsors', [LoanSponsorshipController::class, 'search'])->whereNumber('member')->name('loan-sponsors.search');
    Route::get('/loan-intake', [MemberProfileController::class, 'index'])->name('loan-intake');
    Route::get('/members/{member}/loan-applications/new', [LoanApplicationController::class, 'create'])->whereNumber('member')->name('loan-applications.create');
    Route::post('/members/{member}/loan-applications', [LoanApplicationController::class, 'store'])->whereNumber('member')->name('loan-applications.store');
});

Route::prefix('staff')->name('staff.')->middleware(['auth', 'auth.session', 'can:access-loan-applications'])->group(function (): void {
    Route::get('/loan-applications', [LoanApplicationController::class, 'queue'])->name('loan-applications.queue');
    Route::prefix('members/{member}/loan-applications/{application}')->where(['member' => '[0-9]+', 'application' => '[0-9]+'])
        ->name('loan-applications.')->group(function (): void {
            Route::get('/', [LoanApplicationController::class, 'show'])->name('show');
            Route::patch('/sponsors', [LoanSponsorshipController::class, 'update'])->middleware('can:manage-members')->name('sponsors.update');
            Route::post('/recommend', [LoanApplicationController::class, 'recommend'])->middleware('can:manage-members')->name('recommend');
            Route::post('/review', [LoanApplicationController::class, 'review'])->middleware('can:review-loan-applications')->name('review');
            Route::post('/cancel-for-exit', [LoanApplicationController::class, 'cancelForExit'])->middleware('can:review-loans')->name('cancel-for-exit');
            Route::get('/minutes', [LoanApplicationController::class, 'minutes'])->name('minutes');
            Route::get('/voucher', [LoanApplicationController::class, 'voucher'])->name('voucher');
            Route::get('/top-up-voucher', [LoanApplicationController::class, 'topUpVoucher'])->name('top-up-voucher');
        });
});

Route::prefix('staff')->name('staff.')->middleware(['auth', 'auth.session', 'can:access-task-inbox'])->group(function (): void {
    Route::get('/tasks', [TaskInboxController::class, 'index'])->name('tasks.index');
    Route::get('/tasks/count', [TaskInboxController::class, 'count'])->name('tasks.count');
});
