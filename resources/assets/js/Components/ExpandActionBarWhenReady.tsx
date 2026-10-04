import { type RefObject, useEffect, useRef } from "react";
import { type ActionBarHandle } from "@/Components/ActionBar";
import { useZiggy } from "@/Context/ZiggyContext";

/**
 * Opens a page's action panel once, the first time route authorization is ready.
 *
 * ActionBar ignores expand() until then, so a call made once on mount lands while
 * the shell is still loading and is lost: the bar stays collapsed and the form
 * inside it is clipped off the page. Render this inside the layout, where the
 * readiness context is available, not in the component that renders the layout.
 *
 * Once only: an authorization refresh takes readiness back to loading and then to
 * ready again, and expanding on that second ready would reopen a panel the user
 * had closed.
 */
export default function ExpandActionBarWhenReady({ panel }: { panel: RefObject<ActionBarHandle | null> }) {
    const { status } = useZiggy();
    const expanded = useRef(false);

    useEffect(() => {
        if (status !== "ready" || expanded.current) return;

        expanded.current = true;
        panel.current?.expand();
    }, [status, panel]);

    return null;
}
