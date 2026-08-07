import { Head, Link } from "@inertiajs/react";
import { type ReactNode } from "react";
import { ShieldAlert } from "lucide-react";
import { Badge } from "@/Components/ui/badge";
import { Button } from "@/Components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/Components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/Components/ui/table";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";

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
    return <aside className="space-y-6 p-5 text-sm">
        <div>
            <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">Access-as-Event</p>
            <h2 className="mt-2 font-semibold text-foreground">Clinical access trail</h2>
        </div>
        <p className="text-muted-foreground">Every read of a clinical document is an event. Break-Glass events carry a stated reason and stay flagged for security review.</p>
        {filters.document_id && <div>
            <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">Filtered document</p>
            <code className="mt-2 block rounded bg-muted px-2 py-1 text-xs break-all">{filters.document_id}</code>
            <Button asChild variant="outline" className="mt-3 w-full"><Link href={route("clinicaldocumentation.audit")}>Show every record</Link></Button>
        </div>}
    </aside>;
}

const Audit = ({ events }: Pick<AuditProps, "events">) => <>
    <Head title="Clinical access audit" />
    <p className="mb-6 text-sm text-muted-foreground">Accountable evidence of who reached a clinical record, when, and why.</p>
    <Card>
        <CardHeader>
            <CardTitle className="text-base">Recorded access</CardTitle>
            <CardDescription>Newest first. Audit evidence is insert-only and is never edited or removed.</CardDescription>
        </CardHeader>
        <CardContent>
            <div className="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Occurred</TableHead>
                            <TableHead>Action</TableHead>
                            <TableHead>Actor</TableHead>
                            <TableHead>Reason</TableHead>
                            <TableHead>Document</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {events.data.map((event) => <TableRow key={event.id}>
                            <TableCell className="whitespace-nowrap">{new Date(event.occurred_at).toLocaleString()}</TableCell>
                            <TableCell>
                                <div className="flex items-center gap-2">
                                    <span>{event.action}</span>
                                    {event.metadata?.security_review_required && <Badge variant="destructive"><ShieldAlert className="mr-1 size-3" />review</Badge>}
                                </div>
                            </TableCell>
                            <TableCell>{event.actor_name}</TableCell>
                            <TableCell className="max-w-80 text-muted-foreground">{event.reason ?? "—"}</TableCell>
                            <TableCell><code className="text-xs break-all">{event.document_id ?? "—"}</code></TableCell>
                        </TableRow>)}
                        {events.data.length === 0 && <TableRow>
                            <TableCell colSpan={5} className="text-center text-muted-foreground">No clinical access has been recorded yet.</TableCell>
                        </TableRow>}
                    </TableBody>
                </Table>
            </div>
        </CardContent>
    </Card>
    <div className="mt-4 flex flex-wrap gap-2">
        {events.links.map((link) => link.url
            ? <Button key={link.label} asChild variant={link.active ? "default" : "outline"} size="sm"><Link href={link.url} dangerouslySetInnerHTML={{ __html: link.label }} /></Button>
            : null)}
    </div>
</>;

Audit.layout = (page: ReactNode) => {
    const props = ((page as { props?: AuditProps }).props ?? page) as AuditProps;

    return <AuthenticatedLayout header="Clinical access audit">
        <Content actionBar={<AuditActionBar filters={props.filters} />}>{page}</Content>
    </AuthenticatedLayout>;
};

export default Audit;
