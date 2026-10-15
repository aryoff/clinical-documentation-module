import { Head, Link } from "@inertiajs/react";
import { type ReactNode } from "react";
import { ShieldAlert } from "lucide-react";
import { Badge } from "@/Components/ui/badge";
import { Button } from "@/Components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/Components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/Components/ui/table";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";
import { useModuleT } from "@/hooks/useModuleT";

// Audit actions this module writes; any other code (written by a collaborating module) is shown as recorded.
const auditActionCodes = ["active_clinical_record_read", "addendum_created", "addendum_signed", "allergy_asserted", "archive_requested", "break_glass_read", "diagnosis_asserted", "diagnosis_heads_read", "diagnosis_lineage_read", "diagnostic_result_evidence_recorded", "document_read", "document_signed", "draft_created", "draft_updated", "handoff_accepted", "safety_facts_read", "discharge_summary_drafted", "discharge_summary_signed", "discharge_summary_explained", "external_evidence_file_read", "external_evidence_incorporated", "external_evidence_reviewed", "external_evidence_staged"];

type AuditEvent = {
    id: string;
    action: string;
    actor_name: string;
    document_id: string | null;
    patient_id: string | null;
    reason: string | null;
    correlation_id: string | null;
    metadata: { security_review_required?: boolean } | null;
    occurred_at: string;
};
type AuditProps = {
    events: { data: AuditEvent[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { document_id: string | null; patient_id: string | null };
};

function AuditActionBar({ filters }: Pick<AuditProps, "filters">) {
  const { t } = useModuleT();
    return <aside className={"space-y-6 p-5 text-sm"}>
        <div>
            <p className={"text-xs font-semibold tracking-wider text-muted-foreground uppercase"}>{t("audit.access_as_event")}</p>
            <h2 className={"mt-2 font-semibold text-foreground"}>{t("audit.clinical_access_trail")}</h2>
        </div>
        <p className={"text-muted-foreground"}>{t("audit.every_read_of_a")}</p>
        {filters.document_id && <div>
            <p className={"text-xs font-semibold tracking-wider text-muted-foreground uppercase"}>{t("audit.filtered_document")}</p>
            <code className={"mt-2 block rounded bg-muted px-2 py-1 text-xs break-all"}>{filters.document_id}</code>
            <Button asChild variant={"outline"} className={"mt-3 w-full"}><Link href={route("clinicaldocumentation.audit")}>{t("audit.show_every_record")}</Link></Button>
        </div>}
    </aside>;
}

const Audit = ({ events }: Pick<AuditProps, "events">) => {
  const { t } = useModuleT();
  return <>
    <Head title={t("audit.clinical_access_audit")} />
    <p className={"mb-6 text-sm text-muted-foreground"}>{t("audit.accountable_evidence_of_who")}</p>
    <Card>
        <CardHeader>
            <CardTitle className={"text-base"}>{t("audit.recorded_access")}</CardTitle>
            <CardDescription>{t("audit.newest_first_audit_evidence")}</CardDescription>
        </CardHeader>
        <CardContent>
            <div className={"overflow-x-auto"}>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t("audit.occurred")}</TableHead>
                            <TableHead>{t("audit.action")}</TableHead>
                            <TableHead>{t("audit.actor")}</TableHead>
                            <TableHead>{t("audit.reason")}</TableHead>
                            <TableHead>{t("audit.document")}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {events.data.map((event) => <TableRow key={event.id}>
                            <TableCell className={"whitespace-nowrap"}>{new Date(event.occurred_at).toLocaleString()}</TableCell>
                            <TableCell>
                                <div className={"flex items-center gap-2"}>
                                    <span>{auditActionCodes.includes(event.action) ? t(`audit.action_label.${event.action}`) : event.action}</span>
                                    {event.metadata?.security_review_required && <Badge variant={"destructive"}><ShieldAlert className={"mr-1 size-3"} />{t("audit.review")}</Badge>}
                                </div>
                            </TableCell>
                            <TableCell>{event.actor_name}</TableCell>
                            <TableCell className={"max-w-80 text-muted-foreground"}>{event.reason ?? "—"}</TableCell>
                            <TableCell><code className={"text-xs break-all"}>{event.document_id ?? "—"}</code></TableCell>
                        </TableRow>)}
                        {events.data.length === 0 && <TableRow>
                            <TableCell colSpan={5} className={"text-center text-muted-foreground"}>{t("audit.no_clinical_access_has")}</TableCell>
                        </TableRow>}
                    </TableBody>
                </Table>
            </div>
        </CardContent>
    </Card>
    <div className={"mt-4 flex flex-wrap gap-2"}>
        {events.links.map((link) => link.url
            ? <Button key={link.label} asChild variant={link.active ? "default" : "outline"} size={"sm"}><Link href={link.url} dangerouslySetInnerHTML={{ __html: link.label }} /></Button>
            : null)}
    </div>
</>;
};

Audit.layout = (page: ReactNode) => {
    const props = ((page as { props?: AuditProps }).props ?? page) as AuditProps;

    return <AuthenticatedLayout header={(t) => t("audit.clinical_access_audit")}>
        <Content actionBar={<AuditActionBar filters={props.filters} />}>{page}</Content>
    </AuthenticatedLayout>;
};

export default Audit;
