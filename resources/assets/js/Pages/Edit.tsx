import { Head, router, useForm } from "@inertiajs/react";
import { type FormEvent, type ReactNode, useEffect, useRef, useState } from "react";
import { FileLock2, FilePenLine } from "lucide-react";
import { type ActionBarHandle } from "@/Components/ActionBar";
import { Button } from "@/Components/ui/button";
import { Card, CardDescription, CardHeader, CardTitle } from "@/Components/ui/card";
import { Label } from "@/Components/ui/label";
import { Textarea } from "@/Components/ui/textarea";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";

type Document = { id: string; payload: Record<string, unknown>; encountered_at: string };
type EditProps = { document: Document };
type ClinicalDocumentForm = { payload: Record<string, any>; encountered_at: string };

function EditActionBar({ document }: EditProps) {
    const { put, processing, transform } = useForm<ClinicalDocumentForm>({
        payload: document.payload,
        encountered_at: document.encountered_at,
    });
    const [payload, setPayload] = useState(JSON.stringify(document.payload, null, 2));

    const submit = (event: FormEvent) => {
        event.preventDefault();

        let parsed: Record<string, any>;

        try {
            parsed = JSON.parse(payload);
        } catch {
            alert("Payload must be valid JSON.");

            return;
        }

        // setData only lands on the next render, so the first submit would save
        // the payload the draft already held. transform applies to the request
        // being sent.
        transform((current) => ({ ...current, payload: parsed }));
        put(route("clinicaldocumentation.update", document.id));
    };

    const sign = () => router.post(route("clinicaldocumentation.submit", document.id));

    return <form className="space-y-4 p-5" onSubmit={submit}>
        <div>
            <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">Private draft</p>
            <h2 className="mt-2 font-semibold text-foreground">Continue authoring</h2>
        </div>
        <div className="space-y-2">
            <Label htmlFor="payload">Clinical payload</Label>
            <Textarea id="payload" className="min-h-80 font-mono text-xs" value={payload} onChange={(event) => setPayload(event.target.value)} />
        </div>
        <Button className="w-full" disabled={processing}>Save draft</Button>
        {/* Authoring a draft and signing it are separate authorities. A scribe
            holding only the first reaches this page; `/populateSidebar` strips
            the signing route for them, so the button is absent rather than
            waiting to answer with a 403. */}
        {route().has("clinicaldocumentation.submit") && <>
            <p className="text-xs text-muted-foreground">Signing locks this document permanently. Save first: only what is saved is signed.</p>
            <Button type="button" variant="destructive" className="w-full" disabled={processing} onClick={sign}>
                <FileLock2 className="mr-1 size-4" />Sign and lock
            </Button>
        </>}
    </form>;
}

function EditLayout({ page, document }: { page: ReactNode; document: Document }) {
    const panel = useRef<ActionBarHandle>(null);

    useEffect(() => panel.current?.expand(), []);

    return <AuthenticatedLayout header="Edit clinical draft">
        <Content actionBar={<EditActionBar document={document} />} actionBarRef={panel}>{page}</Content>
    </AuthenticatedLayout>;
}

const Edit = ({ document }: EditProps) => <>
    <Head title="Edit clinical draft" />
    <p className="mb-6 text-sm text-muted-foreground">Drafts remain private to their author. Signing locks this source document permanently; later clarification is a separate addendum.</p>
    <Card>
        <CardHeader>
            <FilePenLine className="size-5 text-primary" />
            <CardTitle>Draft protection</CardTitle>
            <CardDescription>Only you can update this draft. Its recorded encounter time remains {new Date(document.encountered_at).toLocaleString()}.</CardDescription>
        </CardHeader>
    </Card>
</>;

Edit.layout = (page: ReactNode) => {
    const props = ((page as { props?: EditProps }).props ?? page) as EditProps;

    return <EditLayout page={page} document={props.document} />;
};

export default Edit;
