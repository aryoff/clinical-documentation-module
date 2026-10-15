import { Head, useForm } from "@inertiajs/react";
import { type FormEvent, type ReactNode, useRef, useState } from "react";
import { FilePenLine } from "lucide-react";
import { type ActionBarHandle } from "@/Components/ActionBar";
import ExpandActionBarWhenReady from "../Components/ExpandActionBarWhenReady";
import { Button } from "@/Components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/Components/ui/card";
import { Input } from "@/Components/ui/input";
import { Label } from "@/Components/ui/label";
import { Textarea } from "@/Components/ui/textarea";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";
import { useModuleT } from "@/hooks/useModuleT";

type CreateProps = { handoffId: string };
type ClinicalDocumentForm = {
    handoff_id: string;
    template: string;
    template_version: string;
    encountered_at: string;
    payload: Record<string, any>;
};

function CreateActionBar({ handoffId }: CreateProps) {
  const { t } = useModuleT();
    const { data, post, processing, setData, transform, errors } = useForm<ClinicalDocumentForm>({
        handoff_id: handoffId,
        template: "soap",
        template_version: "1.0.0",
        encountered_at: new Date().toISOString(),
        payload: {},
    });
    const [payload, setPayload] = useState('{\n  "subjective": ""\n}');

    const submit = (event: FormEvent) => {
        event.preventDefault();

        let parsed: Record<string, any>;

        try {
            parsed = JSON.parse(payload);
        } catch {
            alert(t("create.payload_must_be_valid"));

            return;
        }

        // setData only lands on the next render, so the first submit would post
        // the untouched initial payload and be rejected as empty. transform
        // applies to the request being sent.
        transform((current) => ({ ...current, payload: parsed }));
        post(route("clinicaldocumentation.store"));
    };

    return <form className={"space-y-4 p-5"} onSubmit={submit}>
        <div>
            <p className={"text-xs font-semibold tracking-wider text-muted-foreground uppercase"}>{t("create.private_draft")}</p>
            <h2 className={"mt-2 font-semibold text-foreground"}>{t("create.clinical_authoring")}</h2>
        </div>
        <div className={"space-y-2"}>
            <Label htmlFor={"template"}>{t("create.template")}</Label>
            <Input id={"template"} value={data.template} onChange={(event) => setData("template", event.target.value)} />
        </div>
        <div className={"space-y-2"}>
            <Label htmlFor={"template-version"}>{t("create.template_version")}</Label>
            <Input id={"template-version"} value={data.template_version} onChange={(event) => setData("template_version", event.target.value)} />
        </div>
        <div className={"space-y-2"}>
            <Label htmlFor={"payload"}>{t("create.clinical_payload")}</Label>
            <Textarea id={"payload"} className={"min-h-64 font-mono text-xs"} value={payload} onChange={(event) => setPayload(event.target.value)} />
        </div>
        {Object.values(errors).map((error) => <p key={String(error)} className={"text-sm text-destructive"}>{String(error)}</p>)}
        <Button className={"w-full"} disabled={processing}>{t("create.create_private_draft")}</Button>
    </form>;
}

function CreateLayout({ page, handoffId }: { page: ReactNode; handoffId: string }) {
    const panel = useRef<ActionBarHandle>(null);

    return <AuthenticatedLayout header={(t) => t("create.new_clinical_document")}>
        <ExpandActionBarWhenReady panel={panel} />
        <Content actionBar={<CreateActionBar handoffId={handoffId} />} actionBarRef={panel}>{page}</Content>
    </AuthenticatedLayout>;
}

const Create = ({ handoffId }: CreateProps) => {
  const { t } = useModuleT();
  return <>
    <Head title={t("create.new_clinical_document")} />
    <p className={"mb-6 text-sm text-muted-foreground"}>{t("create.document_authoring_is_tied")}</p>
    <Card>
        <CardHeader>
            <FilePenLine className={"size-5 text-primary"} />
            <CardTitle>{t("create.accepted_clinical_handoff")}</CardTitle>
            <CardDescription>{t("create.this_handoff_authorizes_you")}</CardDescription>
        </CardHeader>
        <CardContent><code className={"rounded bg-muted px-2 py-1 text-xs"}>{handoffId}</code></CardContent>
    </Card>
</>;
};

Create.layout = (page: ReactNode) => {
    const props = ((page as { props?: CreateProps }).props ?? page) as CreateProps;

    return <CreateLayout page={page} handoffId={props.handoffId} />;
};

export default Create;
