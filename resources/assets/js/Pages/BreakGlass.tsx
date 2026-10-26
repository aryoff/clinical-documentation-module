import { Head, Link, useForm } from "@inertiajs/react";
import { type FormEvent, type ReactNode, useRef } from "react";
import { ShieldAlert, ShieldCheck } from "lucide-react";
import { type ActionBarHandle } from "@/Components/ActionBar";
import ExpandActionBarWhenReady from "../Components/ExpandActionBarWhenReady";
import { Alert, AlertDescription, AlertTitle } from "@/Components/ui/alert";
import { Button } from "@/Components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/Components/ui/card";
import { Label } from "@/Components/ui/label";
import { Textarea } from "@/Components/ui/textarea";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";
import { useModuleT } from "@/hooks/useModuleT";

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

const BreakGlassActionBar = ({ documentId }: BreakGlassProps) => {
  const { t } = useModuleT();
    const { data, post, processing, setData, errors } = useForm({ reason: "" });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route("clinicaldocumentation.break-glass", documentId));
    };

    return <form className={"space-y-4 p-5"} onSubmit={submit}>
        <div>
            <p className={"text-xs font-semibold tracking-wider text-muted-foreground uppercase"}>{t("break_glass.emergency_access")}</p>
            <h2 className={"mt-2 font-semibold text-foreground"}>{t("break_glass.break_glass")}</h2>
        </div>
        <p className={"text-sm text-muted-foreground"}>{t("break_glass.this_grants_one_audited")}</p>
        <div className={"space-y-2"}>
            <Label htmlFor={"reason"}>{t("break_glass.emergency_reason")}</Label>
            <Textarea id={"reason"} required className={"min-h-40"} placeholder={t("break_glass.why_ordinary_treating_access")} value={data.reason} onChange={(event) => setData("reason", event.target.value)} />
        </div>
        {errors.reason && <p className={"text-sm text-destructive"}>{errors.reason}</p>}
        <Button className={"w-full"} variant={"destructive"} disabled={processing}>{t("break_glass.break_glass_and_record")}</Button>
    </form>;
};

const BreakGlassLayout = ({
  page,
  documentId,
}: {
  page: ReactNode;
  documentId: string;
}) => {
    const panel = useRef<ActionBarHandle>(null);

    return <AuthenticatedLayout header={(t) => t("break_glass.emergency_clinical_access")}>
        <ExpandActionBarWhenReady panel={panel} />
        <Content actionBar={<BreakGlassActionBar documentId={documentId} />} actionBarRef={panel}>{page}</Content>
    </AuthenticatedLayout>;
};

const BreakGlass = ({ documentId, document }: BreakGlassProps) => {
  const { t } = useModuleT();
  return <>
    <Head title={t("break_glass.emergency_clinical_access")} />
    <Alert variant={"destructive"} className={"mb-6"}>
        <ShieldAlert className={"size-4"} />
        <AlertTitle>{t("break_glass.break_glass_is_separately")}</AlertTitle>
        <AlertDescription>{t("break_glass.your_name_your_stated")}</AlertDescription>
    </Alert>
    {document
        ? <>
            <Card>
                <CardHeader>
                    <div className={"flex items-start justify-between gap-4"}>
                        <div>
                            <CardTitle>{document.template} <span className={"text-muted-foreground"}>{t("shared.template_version", { version: document.template_version })}</span></CardTitle>
                            <CardDescription className={"mt-2"}>{t("shared.encountered_signed", { encountered: new Date(document.encountered_at).toLocaleString(), signed: document.signed_at ? new Date(document.signed_at).toLocaleString() : t("break_glass.not_yet") })}</CardDescription>
                        </div>
                        <code className={"rounded bg-muted px-2 py-1 text-xs"}>{document.correlation_id}</code>
                    </div>
                </CardHeader>
                <CardContent><pre className={"overflow-auto rounded-md border bg-muted/40 p-4 text-sm"}>{JSON.stringify(document.payload, null, 2)}</pre></CardContent>
            </Card>
            <Card className={"mt-4"}>
                <CardHeader>
                    <ShieldCheck className={"size-5 text-primary"} />
                    <CardTitle className={"text-base"}>{t("break_glass.read_only_and_only")}</CardTitle>
                    <CardDescription>{t("break_glass.this_access_authorizes_no")}</CardDescription>
                </CardHeader>
                <CardContent><Button asChild variant={"outline"}><Link href={route("clinicaldocumentation.index")}>{t("break_glass.back_to_documents")}</Link></Button></CardContent>
            </Card>
        </>
        : <Card>
            <CardHeader>
                <CardTitle>{t("break_glass.signed_document_title", { id: documentId })}</CardTitle>
                <CardDescription>{t("break_glass.state_the_emergency_reason")}</CardDescription>
            </CardHeader>
        </Card>}
</>;
};

BreakGlass.layout = (page: ReactNode) => {
    const props = ((page as { props?: BreakGlassProps }).props ?? page) as BreakGlassProps;

    return <BreakGlassLayout page={page} documentId={props.documentId} />;
};

export default BreakGlass;
