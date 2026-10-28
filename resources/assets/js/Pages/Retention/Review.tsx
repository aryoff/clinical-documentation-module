import { Head, Link } from "@inertiajs/react";
import { type ReactNode } from "react";
import { Badge } from "@/Components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/Components/ui/card";
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
            {review.blockers.length > 0 && (
              <ul id={"review-blockers"} className={"list-disc pl-6"}>
                {review.blockers.map((code) => (
                  <Blocker key={code} code={code} />
                ))}
              </ul>
            )}
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
