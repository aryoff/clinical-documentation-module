import { Head, Link, router } from "@inertiajs/react";
import { type FormEvent, type ReactNode, useEffect, useRef, useState } from "react";
import { FileLock2, History, ShieldCheck } from "lucide-react";
import { type ActionBarHandle } from "@/Components/ActionBar";
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
type ShowProps = { document: Document };

function AddendumActionBar({ document }: ShowProps) {
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

    return <AuthenticatedLayout header="Clinical document">
        <Content actionBar={<AddendumActionBar document={document} />} actionBarRef={panel}>{page}</Content>
    </AuthenticatedLayout>;
}

const Show = ({ document }: ShowProps) => <>
    <Head title="Clinical document" />
    <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-muted-foreground">Signed evidence for this care journey.</p>
        <div className="flex gap-2">
            <Button asChild variant="outline"><Link href={route("clinicaldocumentation.index")}>Back to documents</Link></Button>
            <Button onClick={() => window.dispatchEvent(new Event(openAddendumPanel))}>Add addendum</Button>
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
