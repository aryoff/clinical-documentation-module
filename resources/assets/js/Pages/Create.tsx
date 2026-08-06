import { Head, useForm } from "@inertiajs/react";
import { type FormEvent, type ReactNode, useEffect, useRef, useState } from "react";
import { FilePenLine } from "lucide-react";
import { type ActionBarHandle } from "@/Components/ActionBar";
import { Button } from "@/Components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/Components/ui/card";
import { Input } from "@/Components/ui/input";
import { Label } from "@/Components/ui/label";
import { Textarea } from "@/Components/ui/textarea";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";

type CreateProps = { handoffId: string };
type ClinicalDocumentForm = {
    handoff_id: string;
    template: string;
    template_version: string;
    encountered_at: string;
    payload: Record<string, any>;
};

function CreateActionBar({ handoffId }: CreateProps) {
    const { data, post, processing, setData, errors } = useForm<ClinicalDocumentForm>({
        handoff_id: handoffId,
        template: "soap",
        template_version: "1.0.0",
        encountered_at: new Date().toISOString(),
        payload: {},
    });
    const [payload, setPayload] = useState('{\n  "subjective": ""\n}');

    const submit = (event: FormEvent) => {
        event.preventDefault();

        try {
            setData("payload", JSON.parse(payload));
            post(route("clinicaldocumentation.store"));
        } catch {
            alert("Payload must be valid JSON.");
        }
    };

    return <form className="space-y-4 p-5" onSubmit={submit}>
        <div>
            <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">Private draft</p>
            <h2 className="mt-2 font-semibold text-foreground">Clinical authoring</h2>
        </div>
        <div className="space-y-2">
            <Label htmlFor="template">Template</Label>
            <Input id="template" value={data.template} onChange={(event) => setData("template", event.target.value)} />
        </div>
        <div className="space-y-2">
            <Label htmlFor="template-version">Template version</Label>
            <Input id="template-version" value={data.template_version} onChange={(event) => setData("template_version", event.target.value)} />
        </div>
        <div className="space-y-2">
            <Label htmlFor="payload">Clinical payload</Label>
            <Textarea id="payload" className="min-h-64 font-mono text-xs" value={payload} onChange={(event) => setPayload(event.target.value)} />
        </div>
        {Object.values(errors).map((error) => <p key={String(error)} className="text-sm text-destructive">{String(error)}</p>)}
        <Button className="w-full" disabled={processing}>Create private draft</Button>
    </form>;
}

function CreateLayout({ page, handoffId }: { page: ReactNode; handoffId: string }) {
    const panel = useRef<ActionBarHandle>(null);

    useEffect(() => panel.current?.expand(), []);

    return <AuthenticatedLayout header="New clinical document">
        <Content actionBar={<CreateActionBar handoffId={handoffId} />} actionBarRef={panel}>{page}</Content>
    </AuthenticatedLayout>;
}

const Create = ({ handoffId }: CreateProps) => <>
    <Head title="New clinical document" />
    <p className="mb-6 text-sm text-muted-foreground">Document authoring is tied to the accepted handoff below. The form opens in the action panel so the record context remains visible.</p>
    <Card>
        <CardHeader>
            <FilePenLine className="size-5 text-primary" />
            <CardTitle>Accepted Clinical Handoff</CardTitle>
            <CardDescription>This handoff authorizes you to create a private draft. It does not grant ongoing access outside this care responsibility.</CardDescription>
        </CardHeader>
        <CardContent><code className="rounded bg-muted px-2 py-1 text-xs">{handoffId}</code></CardContent>
    </Card>
</>;

Create.layout = (page: ReactNode) => {
    const props = ((page as { props?: CreateProps }).props ?? page) as CreateProps;

    return <CreateLayout page={page} handoffId={props.handoffId} />;
};

export default Create;
