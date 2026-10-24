/**
 * The Terms of Service and the Privacy Policy shown in a dialog from the
 * sign-up form and from My account.
 *
 * TEMPORARY placeholder text: replace the sections below with the final,
 * legally reviewed wording before going live (this file is the only place the
 * text lives). When the texts change, bump LEGAL_VERSION here and
 * `terms_version` in config/saas.php (SAAS_TERMS_VERSION) so new sign-ups
 * record the new version.
 */

/** Keep in step with `terms_version` in config/saas.php (the server stamps that version on each acceptance). */
export const LEGAL_VERSION = '2026-10-23';

/** "Last updated" line shown in the dialog. */
export const LEGAL_UPDATED = 'October 23, 2026';

/** True while the texts are placeholders; the dialog says so. Set to false with the final wording. */
export const LEGAL_IS_TEMPORARY = true;

/**
 * Each document: a title and its sections (a heading and one or more paragraphs).
 * Keys are what the dialog's `document` prop takes: 'terms' | 'privacy'.
 */
export const LEGAL_DOCUMENTS = Object.freeze({
    terms: {
        title: 'Terms of Service',
        sections: [
            {
                heading: '1. Acceptance of the Terms',
                paragraphs: [
                    'By creating an account and using the PACES Monitoring System you agree to these Terms of Service. If you do not agree, please do not create an account.',
                ],
            },
            {
                heading: '2. Your account',
                paragraphs: [
                    'Give accurate information when you sign up, keep your password private and do not share your account. You are responsible for what is done with your account.',
                ],
            },
            {
                heading: '3. Student and school information',
                paragraphs: [
                    'Enter student information only as your role at the school requires and only when the school is allowed to collect it. Keep everything you see confidential and use it only for the school\'s work.',
                ],
            },
            {
                heading: '4. Acceptable use',
                paragraphs: [
                    'Do not misuse the system, try to reach another school\'s records, or interfere with how it works. Accounts that break these rules may be suspended.',
                ],
            },
            {
                heading: '5. Changes and contact',
                paragraphs: [
                    'These Terms may change. The latest version is always shown here. For questions, please ask your school administrator or the platform provider.',
                ],
            },
        ],
    },
    privacy: {
        title: 'Privacy Policy',
        sections: [
            {
                heading: '1. What we collect',
                paragraphs: [
                    'Your name, email address, contact number and optional address; your role and school; and the student records that you and your school enter, such as names, class, PACE records and, where entered, contact and health details.',
                ],
            },
            {
                heading: '2. How we use it',
                paragraphs: [
                    'To run the system for your school: to sign you in, show each person the right records, and contact you about your account. We do not sell personal information.',
                ],
            },
            {
                heading: '3. Who can see it',
                paragraphs: [
                    'Teachers see the students of their own classes, school administrators see their whole school, and the platform provider\'s staff may see information needed for support. No other school can see your records.',
                ],
            },
            {
                heading: '4. Keeping it safe',
                paragraphs: [
                    'Access is limited by role and passwords are stored in a scrambled form. Please choose a strong password and sign out on shared computers.',
                ],
            },
            {
                heading: '5. Your choices and contact',
                paragraphs: [
                    'You can view and correct your details under My account, or ask your school administrator or the platform provider about your information.',
                ],
            },
        ],
    },
});
