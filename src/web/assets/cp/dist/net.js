(function () {
    window.Ghostwriter = window.Ghostwriter || {};

    /**
     * Whether a request failed on the way rather than being refused: no
     * answer at all (the network changed or dropped), or the server busy or
     * timing out. One worth asking again: a poll that stops on one leaves
     * the screen waiting for ever.
     */
    window.Ghostwriter.transient = function (error) {
        const status = error?.response?.status;

        if (status !== undefined && status !== null && status !== 0) return status === 408 || status === 429 || status >= 500;

        return Boolean(error?.isAxiosError || error?.request || error instanceof TypeError);
    };
})();
