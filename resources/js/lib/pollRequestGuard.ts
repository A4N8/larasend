export function createPollRequestGuard() {
    let inFlight = false;
    const navigations = new Set<symbol>();

    return {
        beginNavigation: () => {
            const navigation = Symbol();
            navigations.add(navigation);

            return () => {
                navigations.delete(navigation);
            };
        },
        onBefore: () => !inFlight && navigations.size === 0,
        onStart: () => {
            inFlight = true;
        },
        onFinish: () => {
            inFlight = false;
        },
    };
}
