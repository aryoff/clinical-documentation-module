import { Head, Link, router } from "@inertiajs/react";
import { type FormEvent, type ReactNode, useEffect, useRef, useState } from "react";
import { Archive, FileLock2, History, ShieldCheck } from "lucide-react";
import { type ActionBarHandle } from "@/Components/ActionBar";
import { Alert, AlertDescription, AlertTitle } from "@/Components/ui/alert";
import { Badge } from "@/Components/ui/badge";
import { Button } from "@/Components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/Components/ui/card";
import { Input } from "@/Components/ui/input";
import { Label } from "@/Components/ui/label";
import { Textarea } from "@/Components/ui/textarea";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";

const openAddendumPanel = "clinicaldocumentation:open-addendum-panel";

type Document = {
    document_id: string;
    template: string;
    template_version: string;
    status: string;
    payload: Record<string, unknown>;
    encountered_at: string;
    signed_at: string | null;
};
type ShowProps = { document: Document; immutabilityNotice?: string | null; canArchive?: boolean };

function AddendumActionBar({ document }: Pick<ShowProps, "document">) {
    const [reason, setReason] = useState("");
    const [payload, setPayload] = useState("{}");

    const amend = (event: FormEvent) => {
        event.preventDefault();

        try {
            router.post(route("clinicaldocumentation.amend", document.document_id), {
                reason,
                payload: JSON.parse(payload),
                encountered_at: new Date().toISOString(),
            });
        } catch {
            alert("Addendum payload must be valid JSON.");
        }
    };

    return <form className="space-y-4 p-5" onSubmit={amend}>
        <div>
            <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">Linked correction</p>
            <h2 className="mt-2 font-semibold text-foreground">Signed addendum</h2>
        </div>
        <p className="text-sm text-muted-foreground">An addendum preserves this signed source and records why new information is needed.</p>
        <div className="space-y-2">
            <Label htmlFor="reason">Reason</Label>
            <Input id="reason" required placeholder="Clarification or correction reason" value={reason} onChange={(event) => setReason(event.target.value)} />
        </div>
        <div className="space-y-2">
            <Label htmlFor="addendum-payload">Addendum payload</Label>
            <Textarea id="addendum-payload" className="min-h-48 font-mono text-xs" value={payload} onChange={(event) => setPayload(event.target.value)} />
        </div>
        <Button className="w-full">Record signed addendum</Button>
    </form>;
}

function ShowLayout({ page, document }: { page: ReactNode; document: Document }) {
    const panel = useRef<ActionBarHandle>(null);

    useEffect(() => {
        const open = () => panel.current?.expand();

        window.addEventListener(openAddendumPanel, open);

        return () => window.removeEventListener(openAddendumPanel, open);
    }, []);

    // Reading a signed document and amending one are separate authorities, and
    // Break-Glass buys a read and never a write. Without the amend route the
    // panel has nothing to offer, so the ActionBar is not fitted at all.
    const canAmend = route().has("clinicaldocumentation.amend");

    return <AuthenticatedLayout header="Clinical document">
        <Content actionBar={canAmend ? <AddendumActionBar document={document} /> : undefined} actionBarRef={panel}>{page}</Content>
    </AuthenticatedLayout>;
}

const Show = ({ document, immutabilityNotice, canArchive }: ShowProps) => <>
    <Head title="Clinical document" />
    {immutabilityNotice && <Alert className="mb-6">
        <FileLock2 className="size-4" />
        <AlertTitle>{immutabilityNotice}</AlertTitle>
        <AlertDescription>
            The signed source stays exactly as you signed it. Record what changed as a reasoned addendum instead.
            {route().has("clinicaldocumentation.amend") && <Button className="mt-3" onClick={() => window.dispatchEvent(new Event(openAddendumPanel))}>Record an addendum instead</Button>}
        </AlertDescription>
    </Alert>}
    <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-muted-foreground">Signed evidence for this care journey.</p>
        <div className="flex flex-wrap gap-2">
            <Button asChild variant="outline"><Link href={route("clinicaldocumentation.index")}>Back to documents</Link></Button>
            {route().has("clinicaldocumentation.audit") && <Button asChild variant="outline">
                <Link href={route("clinicaldocumentation.audit", { document_id: document.document_id })}>Access audit</Link>
            </Button>}
            {canArchive && <Button variant="outline" onClick={() => router.post(route("clinicaldocumentation.archive", document.document_id))}>
                <Archive className="mr-1 size-4" />Request archive
            </Button>}
            {route().has("clinicaldocumentation.amend") && <Button onClick={() => window.dispatchEvent(new Event(openAddendumPanel))}>Add addendum</Button>}
        </div>
    </div>
    <Card>
        <CardHeader>
            <div className="flex items-start justify-between gap-4">
                <div>
                    <CardTitle>{document.template} <span className="text-muted-foreground">v{document.template_version}</span></CardTitle>
                    <CardDescription className="mt-2">Encountered {new Date(document.encountered_at).toLocaleString()} · signed {document.signed_at ? new Date(document.signed_at).toLocaleString() : "not yet"}</CardDescription>
                </div>
                <Badge><FileLock2 className="mr-1 size-3" />{document.status}</Badge>
            </div>
        </CardHeader>
        <CardContent><pre className="overflow-auto rounded-md border bg-muted/40 p-4 text-sm">{JSON.stringify(document.payload, null, 2)}</pre></CardContent>
    </Card>
    <div className="mt-4 grid gap-4 md:grid-cols-2">
        <Card><CardHeader><ShieldCheck className="size-5 text-primary" /><CardTitle className="text-base">Immutable source</CardTitle><CardDescription>Access, emergency use, and future addenda preserve accountable evidence.</CardDescription></CardHeader></Card>
        <Card><CardHeader><History className="size-5 text-primary" /><CardTitle className="text-base">Correction history</CardTitle><CardDescription>Use the action panel to add a reasoned record without changing this document.</CardDescription></CardHeader></Card>
    </div>
</>;

Show.layout = (page: ReactNode) => {
    const props = ((page as { props?: ShowProps }).props ?? page) as ShowProps;

    return <ShowLayout page={page} document={props.document} />;
};

export default Show;
