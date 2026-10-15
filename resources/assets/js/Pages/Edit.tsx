import { Head, router, useForm } from "@inertiajs/react";
import { type FormEvent, type ReactNode, useRef, useState } from "react";
import { FileLock2, FilePenLine } from "lucide-react";
import { type ActionBarHandle } from "@/Components/ActionBar";
import ExpandActionBarWhenReady from "../Components/ExpandActionBarWhenReady";
import { Button } from "@/Components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/Components/ui/card";
import { Label } from "@/Components/ui/label";
import { Textarea } from "@/Components/ui/textarea";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";
import { useModuleT } from "@/hooks/useModuleT";
import { joinParts } from "../Support/ids";

type Document = { id: string; payload: Record<string, unknown>; encountered_at: string };
type PresentedExternalEvidence = { id: string; claim: string; staged_by_name: string; staged_at: string; original_filename: string | null; file_url: string; can_open_file: boolean };
type ReviewedExternalEvidence = PresentedExternalEvidence & { incorporated: boolean };
type EditProps = { document: Document; presentedExternalEvidence: PresentedExternalEvidence[]; reviewedExternalEvidence: ReviewedExternalEvidence[]; presentedExternalEvidenceIncorporated: boolean; clinicalDocumentDraftUpdated: boolean };
type ClinicalDocumentForm = { payload: Record<string, any>; encountered_at: string };

function EditActionBar({ document }: { document: Document }) {
  const { t } = useModuleT();
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
            alert(t("edit.payload_must_be_valid"));

            return;
        }

        // setData only lands on the next render, so the first submit would save
        // the payload the draft already held. transform applies to the request
        // being sent.
        transform((current) => ({ ...current, payload: parsed }));
        put(route("clinicaldocumentation.update", document.id));
    };

    const sign = () => router.post(route("clinicaldocumentation.submit", document.id));

    return <form className={"space-y-4 p-5"} onSubmit={submit}>
        <div>
            <p className={"text-xs font-semibold tracking-wider text-muted-foreground uppercase"}>{t("edit.private_draft")}</p>
            <h2 className={"mt-2 font-semibold text-foreground"}>{t("edit.continue_authoring")}</h2>
        </div>
        <div className={"space-y-2"}>
            <Label htmlFor={"payload"}>{t("edit.clinical_payload")}</Label>
            <Textarea id={"payload"} className={"min-h-80 font-mono text-xs"} value={payload} onChange={(event) => setPayload(event.target.value)} />
        </div>
        <Button className={"w-full"} disabled={processing}>{t("edit.save_draft")}</Button>
        {/* Authoring a draft and signing it are separate authorities. A scribe
            holding only the first reaches this page; `/populateSidebar` strips
            the signing route for them, so the button is absent rather than
            waiting to answer with a 403. */}
        {route().has("clinicaldocumentation.submit") && <>
            <p className={"text-xs text-muted-foreground"}>{t("edit.signing_locks_this_document")}</p>
            <Button type={"button"} variant={"destructive"} className={"w-full"} disabled={processing} onClick={sign}>
                <FileLock2 className={"mr-1 size-4"} />{t("edit.sign_and_lock")}</Button>
        </>}
    </form>;
}

function EditLayout({ page, document }: { page: ReactNode; document: Document }) {
    const panel = useRef<ActionBarHandle>(null);

    return <AuthenticatedLayout header={(t) => t("edit.edit_clinical_draft")}>
        <ExpandActionBarWhenReady panel={panel} />
        <Content actionBar={<EditActionBar document={document} />} actionBarRef={panel}>{page}</Content>
    </AuthenticatedLayout>;
}

const Edit = ({ document, presentedExternalEvidence, reviewedExternalEvidence, presentedExternalEvidenceIncorporated, clinicalDocumentDraftUpdated }: EditProps) => {
  const { t } = useModuleT();
  return <>
    <Head title={t("edit.edit_clinical_draft")} />
    <p className={"mb-6 text-sm text-muted-foreground"}>{t("edit.drafts_remain_private_to")}</p>
    {reviewedExternalEvidence.some((evidence) => !evidence.incorporated) && <div className={"mb-6 rounded-md border border-primary/30 bg-primary/5 p-3 text-sm"} data-testid={"external-evidence-reviewed"}>
        <p>{t("edit.evidence_review_recorded_decide")}</p>
        {reviewedExternalEvidence.filter((evidence) => !evidence.incorporated).map((evidence) => route().has("clinicaldocumentation.presented-external-evidence.incorporate") && <Button key={evidence.id} id={joinParts("", "record-external-evidence-incorporation-", evidence.id)} className={"mt-3 mr-2"} size={"sm"} onClick={() => router.post(route("clinicaldocumentation.presented-external-evidence.incorporate", evidence.id), { document_id: document.id })}>{t("edit.record_incorporation_decision")}</Button>)}
    </div>}
    {(presentedExternalEvidenceIncorporated || reviewedExternalEvidence.some((evidence) => evidence.incorporated)) && <p className={"mb-6 rounded-md border border-primary/30 bg-primary/5 p-3 text-sm"} data-testid={"external-evidence-incorporated"}>{t("edit.incorporation_decision_recorded_add")}</p>}
    {clinicalDocumentDraftUpdated && <p className={"mb-6 rounded-md border border-primary/30 bg-primary/5 p-3 text-sm"} data-testid={"clinical-draft-updated"}>{t("edit.draft_saved")}</p>}
    <div className={"space-y-6"}>
        <Card>
            <CardHeader>
                <FilePenLine className={"size-5 text-primary"} />
                <CardTitle>{t("edit.draft_protection")}</CardTitle>
                <CardDescription>{t("edit.draft_protection_note", { time: new Date(document.encountered_at).toLocaleString() })}</CardDescription>
            </CardHeader>
        </Card>
        {presentedExternalEvidence.length > 0 && <Card>
            <CardHeader>
                <CardTitle>{t("edit.staged_external_evidence")}</CardTitle>
                <CardDescription>{t("edit.review_the_custody_record")}</CardDescription>
            </CardHeader>
            <CardContent className={"space-y-3"}>
                {presentedExternalEvidence.map((evidence) => <div key={evidence.id} className={"rounded-md border p-4"} data-testid={"staged-external-evidence"}>
                    <p className={"font-medium"}>{evidence.claim}</p>
                    <p className={"mt-1 text-sm text-muted-foreground"}>{t("shared.staged_by_line", { file: evidence.original_filename ?? t("edit.uploaded_file"), name: evidence.staged_by_name })}</p>
                    <div className={"mt-3 flex flex-wrap gap-2"}>
                        {evidence.can_open_file && route().has("clinicaldocumentation.presented-external-evidence.file") && <Button asChild size={"sm"} variant={"outline"}><a href={evidence.file_url}>{t("edit.open_staged_file")}</a></Button>}
                        {route().has("clinicaldocumentation.presented-external-evidence.review") && <Button size={"sm"} onClick={() => router.post(route("clinicaldocumentation.presented-external-evidence.review", evidence.id), { document_id: document.id })}>{t("edit.review_for_authoring")}</Button>}
                    </div>
                </div>)}
            </CardContent>
        </Card>}
    </div>
</>;
};

Edit.layout = (page: ReactNode) => {
    const props = ((page as { props?: EditProps }).props ?? page) as EditProps;

    return <EditLayout page={page} document={props.document} />;
};

export default Edit;
