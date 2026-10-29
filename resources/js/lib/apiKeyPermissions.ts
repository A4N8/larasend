export type ApiKeyScope = 'send' | 'read:activity' | 'manage:suppressions';

export function apiKeyScopeChoices(
    canManageSuppressions: boolean,
): { value: ApiKeyScope; label: string }[] {
    const choices: { value: ApiKeyScope; label: string }[] = [
        { value: 'send', label: 'Send email' },
        { value: 'read:activity', label: 'Read activity' },
    ];

    if (canManageSuppressions) {
        choices.push({
            value: 'manage:suppressions',
            label: 'Manage suppressions',
        });
    }

    return choices;
}

export function apiKeyScopes(apiKey: {
    scopes: ApiKeyScope[] | null;
}): ApiKeyScope[] {
    return apiKey.scopes ?? ['send', 'read:activity'];
}
