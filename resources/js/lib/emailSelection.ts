type EmailPreview = {
    id: string;
    html: string | null;
    text?: string | null;
    bodyLoaded?: boolean;
    bodyError?: boolean;
};
type SelectionState<T extends EmailPreview> = { value: T | null };
type ReloadPreview<T extends EmailPreview> = (
    id: string,
    receive: (detail: T | null) => void,
    fail: () => void,
) => void;

const previewRequests = new WeakMap<object, symbol>();

export function selectEmailPreview<T extends EmailPreview>(
    state: SelectionState<T>,
    email: T | null,
    reload: ReloadPreview<T>,
): void {
    const request = Symbol();
    previewRequests.set(state, request);
    state.value = email ? { ...email, bodyError: false } : null;
    reload(
        email?.id ?? '',
        (detail) => {
            if (
                !email ||
                state.value?.id !== email.id ||
                previewRequests.get(state) !== request
            ) {
                return;
            }

            if (detail === null || detail.id === email.id) {
                state.value = detail;
            }
        },
        () => {
            if (
                email &&
                state.value?.id === email.id &&
                previewRequests.get(state) === request
            ) {
                state.value = { ...state.value, bodyError: true };
            }
        },
    );
}

export function refreshEmailSelection<T extends EmailPreview>(
    selected: T | null,
    rows: readonly { id: string }[],
    filtersChanged = false,
): T | null {
    if (!selected || filtersChanged) {
        return null;
    }

    const refreshed = rows.find((row) => row.id === selected.id);

    if (!refreshed) {
        return selected;
    }

    return {
        ...selected,
        ...refreshed,
        html: selected.html,
        text: selected.text,
        bodyLoaded: selected.bodyLoaded,
    };
}
