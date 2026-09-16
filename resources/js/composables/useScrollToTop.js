export function shouldResetScroll(visit) {
    return visit?.preserveScroll !== true;
}

export function forceScrollToTop() {
    if (typeof window === 'undefined') return;

    const html = document.documentElement;
    const previousBehavior = html.style.scrollBehavior;

    html.style.scrollBehavior = 'auto';

    const scroll = () => {
        window.scrollTo(0, 0);
        html.scrollTop = 0;
        document.body.scrollTop = 0;
    };

    scroll();
    requestAnimationFrame(scroll);
    requestAnimationFrame(() => requestAnimationFrame(scroll));
    setTimeout(scroll, 0);
    setTimeout(scroll, 50);
    setTimeout(scroll, 120);
    setTimeout(() => {
        html.style.scrollBehavior = previousBehavior;
    }, 150);
}
