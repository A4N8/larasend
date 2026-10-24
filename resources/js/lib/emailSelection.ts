type EmailPreview = { id: string; html: string | null };
type SelectionState<T extends EmailPreview> = { value: T | null };
type ReloadPreview<T extends EmailPreview> = (
    id: string,
    receive: (detail: T | null) => void,
) => void;

export function selectEmailPreview<T extends EmailPreview>(
    state: SelectionState<T>,
    email: T | null,
    reload: ReloadPreview<T>,
): void {
    state.value = email;
    reload(email?.id ?? '', (detail) => {
        if (!email || state.value?.id !== email.id) {
            return;
        }

        if (detail === null || detail.id === email.id) {
            state.value = detail;
        }
    });
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

    return { ...selected, ...refreshed, html: selected.html };
}
