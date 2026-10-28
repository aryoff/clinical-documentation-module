import { Head, Link, useForm } from "@inertiajs/react";
import { type ReactNode } from "react";
import { Badge } from "@/Components/ui/badge";
import { Button } from "@/Components/ui/button";
import { Checkbox } from "@/Components/ui/checkbox";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/Components/ui/card";
import { Input } from "@/Components/ui/input";
import { Label } from "@/Components/ui/label";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";
import { useModuleT } from "@/hooks/useModuleT";

type Hold = { id: string; scope: string; reason: string; placed_by_name: string | null; placed_at: string };
type ReviewProps = {
  review: {
    patient_id: string;
    schedule_version: number | null;
    minimum_years: number | null;
    last_visit_ended_at: string | null;
    coverage_through: string;
    coverage_complete: boolean;
    protected_until: string | null;
    extension_until: string | null;
    holds: Hold[];
    blockers: string[];
    eligible_for_review: boolean;
    destruction_enabled: boolean;
  };
};

const Line = ({ id, label, value }: { id: string; label: string; value: ReactNode }) => (
  <p id={id}>
    <strong>{label}</strong> {value}
  </p>
);

// A blocker is "code" or "code:detail"; the code picks the sentence and the detail rides along.
const Blocker = ({ code }: { code: string }) => {
  const { t } = useModuleT();
  const [kind, ...rest] = code.split(":");
  return <li>{t(`retention.blocker.${kind}`, { detail: rest.join(":") })}</li>;
};

const DestroyForm = ({ patientId }: { patientId: string }) => {
  const { t } = useModuleT();
  const form = useForm({ reason: "", dependencies_acknowledged: false });
  // One bag for every error, since the server also answers with a `destruction` error that is not one of the fields.
  const errors: Record<string, string | undefined> = form.errors;
  return (
    <form
      id={"destroy-form"}
      className={"space-y-3"}
      onSubmit={(event) => {
        event.preventDefault();
        form.post(route("clinicaldocumentation.retention.destroy", patientId), { preserveScroll: true });
      }}
    >
      <p>{t("destruction.review_hint")}</p>
      <Label htmlFor={"destroy-reason"}>{t("destruction.reason")}</Label>
      <Input id={"destroy-reason"} value={form.data.reason} onChange={(e) => form.setData("reason", e.target.value)} />
      {errors.reason && <p className={"text-sm text-destructive"}>{errors.reason}</p>}
      <label className={"flex items-center gap-1 text-sm"}>
        <Checkbox checked={form.data.dependencies_acknowledged} onCheckedChange={(checked) => form.setData("dependencies_acknowledged", checked === true)} />
        {t("destruction.acknowledge_dependencies")}
      </label>
      {errors.dependencies_acknowledged && <p className={"text-sm text-destructive"}>{errors.dependencies_acknowledged}</p>}
      {errors.destruction && <p className={"text-sm text-destructive"}>{errors.destruction}</p>}
      <Button id={"destroy-findings"} type={"submit"} disabled={form.processing}>
        {t("destruction.destroy")}
      </Button>
    </form>
  );
};

const Review = ({ review }: ReviewProps) => {
  const { t } = useModuleT();
  const missing = t("retention.not_recorded");
  const when = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString() : missing);
  return (
    <>
      <Head title={t("retention.review_title")} />
      <Content>
        <Card>
          <CardHeader>
            <CardTitle>{t("retention.review_title")}</CardTitle>
            <CardDescription>{review.destruction_enabled ? t("retention.destruction_enabled") : t("retention.destruction_disabled")}</CardDescription>
          </CardHeader>
          <CardContent className={"space-y-2"}>
            <Line id={"review-patient"} label={t("retention.patient_id")} value={review.patient_id} />
            <Line id={"review-schedule"} label={t("retention.schedule")} value={review.schedule_version ? t("retention.schedule_value", { version: review.schedule_version, years: review.minimum_years ?? 0 }) : missing} />
            <Line id={"review-last-visit"} label={t("retention.last_visit")} value={when(review.last_visit_ended_at)} />
            <Line id={"review-coverage"} label={t("retention.coverage")} value={t(review.coverage_complete ? "retention.coverage_complete" : "retention.coverage_incomplete", { through: when(review.coverage_through) })} />
            <Line id={"review-protected-until"} label={t("retention.protected_until")} value={when(review.protected_until)} />
            <Line id={"review-extension"} label={t("retention.extension_until")} value={review.extension_until ?? missing} />
            <p id={"review-outcome"}>
              <Badge variant={review.eligible_for_review ? "secondary" : "destructive"}>{review.eligible_for_review ? t("retention.may_be_reviewed") : t("retention.must_be_retained")}</Badge>
            </p>
            {review.holds.length > 0 && (
              <ul id={"review-holds"} className={"list-disc pl-6"}>
                {review.holds.map((hold) => (
                  <li key={hold.id}>{t("retention.hold_line", { reason: hold.reason, by: hold.placed_by_name ?? missing, at: when(hold.placed_at) })}</li>
                ))}
              </ul>
            )}
            {review.blockers.length > 0 && (
              <ul id={"review-blockers"} className={"list-disc pl-6"}>
                {review.blockers.map((code) => (
                  <Blocker key={code} code={code} />
                ))}
              </ul>
            )}
            {review.eligible_for_review && review.destruction_enabled && route().has("clinicaldocumentation.retention.destroy") && <DestroyForm patientId={review.patient_id} />}
            {route().has("clinicaldocumentation.retention.index") && (
              <Link className={"underline"} href={route("clinicaldocumentation.retention.index")}>
                {t("retention.back")}
              </Link>
            )}
          </CardContent>
        </Card>
      </Content>
    </>
  );
};
Review.layout = (page: ReactNode) => <AuthenticatedLayout children={page} header={(t) => t("retention.review_title")} />;

export default Review;
