import { Head, Link, useForm } from "@inertiajs/react";
import { type FormEvent, type ReactNode, useEffect, useRef } from "react";
import { ShieldAlert, ShieldCheck } from "lucide-react";
import { type ActionBarHandle } from "@/Components/ActionBar";
import { Alert, AlertDescription, AlertTitle } from "@/Components/ui/alert";
import { Button } from "@/Components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/Components/ui/card";
import { Label } from "@/Components/ui/label";
import { Textarea } from "@/Components/ui/textarea";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";

type BreakGlassDocument = {
    document_id: string;
    template: string;
    template_version: string;
    payload: Record<string, unknown>;
    encountered_at: string;
    signed_at: string | null;
    correlation_id: string;
    security_review_required: boolean;
};
type BreakGlassProps = { documentId: string; document?: BreakGlassDocument };

function BreakGlassActionBar({ documentId }: BreakGlassProps) {
    const { data, post, processing, setData, errors } = useForm({ reason: "" });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route("clinicaldocumentation.break-glass", documentId));
    };

    return <form className="space-y-4 p-5" onSubmit={submit}>
        <div>
            <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">Emergency access</p>
            <h2 className="mt-2 font-semibold text-foreground">Break Glass</h2>
        </div>
        <p className="text-sm text-muted-foreground">This grants one audited read of signed evidence. It grants no authoring and no ongoing access, and it is flagged for security review.</p>
        <div className="space-y-2">
            <Label htmlFor="reason">Emergency reason</Label>
            <Textarea id="reason" required className="min-h-40" placeholder="Why ordinary treating access is unavailable right now" value={data.reason} onChange={(event) => setData("reason", event.target.value)} />
        </div>
        {errors.reason && <p className="text-sm text-destructive">{errors.reason}</p>}
        <Button className="w-full" variant="destructive" disabled={processing}>Break Glass and record the reason</Button>
    </form>;
}

function BreakGlassLayout({ page, documentId }: { page: ReactNode; documentId: string }) {
    const panel = useRef<ActionBarHandle>(null);

    useEffect(() => panel.current?.expand(), []);

    return <AuthenticatedLayout header="Emergency clinical access">
        <Content actionBar={<BreakGlassActionBar documentId={documentId} />} actionBarRef={panel}>{page}</Content>
    </AuthenticatedLayout>;
}

const BreakGlass = ({ documentId, document }: BreakGlassProps) => <>
    <Head title="Emergency clinical access" />
    <Alert variant="destructive" className="mb-6">
        <ShieldAlert className="size-4" />
        <AlertTitle>Break-Glass is separately audited</AlertTitle>
        <AlertDescription>Your name, your stated reason, and the time of access are recorded against this record and reviewed by the security team.</AlertDescription>
    </Alert>
    {document
        ? <>
            <Card>
                <CardHeader>
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <CardTitle>{document.template} <span className="text-muted-foreground">v{document.template_version}</span></CardTitle>
                            <CardDescription className="mt-2">Encountered {new Date(document.encountered_at).toLocaleString()} · signed {document.signed_at ? new Date(document.signed_at).toLocaleString() : "not yet"}</CardDescription>
                        </div>
                        <code className="rounded bg-muted px-2 py-1 text-xs">{document.correlation_id}</code>
                    </div>
                </CardHeader>
                <CardContent><pre className="overflow-auto rounded-md border bg-muted/40 p-4 text-sm">{JSON.stringify(document.payload, null, 2)}</pre></CardContent>
            </Card>
            <Card className="mt-4">
                <CardHeader>
                    <ShieldCheck className="size-5 text-primary" />
                    <CardTitle className="text-base">Read only, and only this once</CardTitle>
                    <CardDescription>This access authorizes no draft, no addendum, and no further read. Request an accepted Clinical Handoff for continuing care.</CardDescription>
                </CardHeader>
                <CardContent><Button asChild variant="outline"><Link href={route("clinicaldocumentation.index")}>Back to documents</Link></Button></CardContent>
            </Card>
        </>
        : <Card>
            <CardHeader>
                <CardTitle>Signed clinical document {documentId}</CardTitle>
                <CardDescription>State the emergency reason in the action panel to open this record. Nothing is disclosed until a reason is recorded.</CardDescription>
            </CardHeader>
        </Card>}
</>;

BreakGlass.layout = (page: ReactNode) => {
    const props = ((page as { props?: BreakGlassProps }).props ?? page) as BreakGlassProps;

    return <BreakGlassLayout page={page} documentId={props.documentId} />;
};

export default BreakGlass;
