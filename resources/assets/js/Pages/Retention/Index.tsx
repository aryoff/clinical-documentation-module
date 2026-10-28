import { Head, router, useForm } from "@inertiajs/react";
import { type ReactNode, useState } from "react";
import { Button } from "@/Components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/Components/ui/card";
import { Input } from "@/Components/ui/input";
import { Label } from "@/Components/ui/label";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/Components/ui/table";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";
import { useModuleT } from "@/hooks/useModuleT";
import { joinParts } from "../../Support/ids";

type Schedule = { id: string; version: number; minimum_years: number; rationale: string; created_by_name: string | null; created_at: string };
type Hold = { id: string; patient_id: string; scope: string; reason: string; placed_by_name: string | null; placed_at: string };
type IndexProps = { schedules: Schedule[]; holds: Hold[]; floor_years: number; destruction_enabled: boolean };

const FieldError = ({ message }: { message?: string }) => (message ? <p className={"text-sm text-destructive"}>{message}</p> : null);

const ScheduleForm = ({ floor }: { floor: number }) => {
  const { t } = useModuleT();
  const form = useForm({ minimum_years: String(floor), rationale: "" });
  return (
    <form
      id={"retention-schedule-form"}
      className={"space-y-3"}
      onSubmit={(event) => {
        event.preventDefault();
        form.post(route("clinicaldocumentation.retention.schedules.store"), { preserveScroll: true, onSuccess: () => form.reset("rationale") });
      }}
    >
      <Label htmlFor={"schedule-years"}>{t("retention.minimum_years")}</Label>
      <Input id={"schedule-years"} type={"number"} value={form.data.minimum_years} onChange={(e) => form.setData("minimum_years", e.target.value)} />
      <FieldError message={form.errors.minimum_years} />
      <Label htmlFor={"schedule-rationale"}>{t("retention.rationale")}</Label>
      <Input id={"schedule-rationale"} value={form.data.rationale} onChange={(e) => form.setData("rationale", e.target.value)} />
      <FieldError message={form.errors.rationale} />
      <Button id={"record-schedule"} type={"submit"} disabled={form.processing}>
        {t("retention.record_schedule")}
      </Button>
    </form>
  );
};

const HoldForm = () => {
  const { t } = useModuleT();
  const form = useForm({ patient_id: "", scope: "patient", reason: "" });
  return (
    <form
      id={"retention-hold-form"}
      className={"space-y-3"}
      onSubmit={(event) => {
        event.preventDefault();
        form.post(route("clinicaldocumentation.retention.holds.store"), { preserveScroll: true, onSuccess: () => form.reset("reason") });
      }}
    >
      <Label htmlFor={"hold-patient"}>{t("retention.patient_id")}</Label>
      <Input id={"hold-patient"} value={form.data.patient_id} onChange={(e) => form.setData("patient_id", e.target.value)} />
      <FieldError message={form.errors.patient_id} />
      <Label htmlFor={"hold-reason"}>{t("retention.reason")}</Label>
      <Input id={"hold-reason"} value={form.data.reason} onChange={(e) => form.setData("reason", e.target.value)} />
      <FieldError message={form.errors.reason} />
      <Button id={"place-hold"} type={"submit"} disabled={form.processing}>
        {t("retention.place_hold")}
      </Button>
    </form>
  );
};

const LiftHoldButton = ({ hold }: { hold: Hold }) => {
  const { t } = useModuleT();
  const [reason, setReason] = useState("");
  const buttonId = joinParts("-", "lift-hold", hold.id);
  return (
    <div className={"flex items-center gap-2"}>
      <Input aria-label={t("retention.lift_reason")} value={reason} onChange={(e) => setReason(e.target.value)} />
      <Button id={buttonId} variant={"outline"} onClick={() => router.post(route("clinicaldocumentation.retention.holds.lift", hold.id), { reason }, { preserveScroll: true })}>
        {t("retention.lift_hold")}
      </Button>
    </div>
  );
};

const ReviewLookup = () => {
  const { t } = useModuleT();
  const [patientId, setPatientId] = useState("");
  return (
    <form
      id={"retention-review-form"}
      className={"flex items-end gap-2"}
      onSubmit={(event) => {
        event.preventDefault();
        router.get(route("clinicaldocumentation.retention.review", patientId));
      }}
    >
      <div className={"flex-1 space-y-1"}>
        <Label htmlFor={"review-patient"}>{t("retention.patient_id")}</Label>
        <Input id={"review-patient"} value={patientId} onChange={(e) => setPatientId(e.target.value)} />
      </div>
      <Button id={"open-review"} type={"submit"}>
        {t("retention.open_review")}
      </Button>
    </form>
  );
};

const Index = ({ schedules, holds, floor_years, destruction_enabled }: IndexProps) => {
  const { t } = useModuleT();
  return (
    <>
      <Head title={t("retention.title")} />
      <Content>
        <div className={"space-y-6"}>
          <Card>
            <CardHeader>
              <CardTitle>{t("retention.title")}</CardTitle>
              <CardDescription>{destruction_enabled ? t("retention.destruction_enabled") : t("retention.destruction_disabled")}</CardDescription>
            </CardHeader>
            <CardContent>
              <ReviewLookup />
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>{t("retention.schedules")}</CardTitle>
              <CardDescription>{t("retention.schedule_rule", { years: floor_years })}</CardDescription>
            </CardHeader>
            <CardContent className={"space-y-4"}>
              {route().has("clinicaldocumentation.retention.schedules.store") && <ScheduleForm floor={floor_years} />}
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t("retention.version")}</TableHead>
                    <TableHead>{t("retention.minimum_years")}</TableHead>
                    <TableHead>{t("retention.rationale")}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {schedules.map((schedule) => (
                    <TableRow key={schedule.id}>
                      <TableCell>{schedule.version}</TableCell>
                      <TableCell>{schedule.minimum_years}</TableCell>
                      <TableCell>{schedule.rationale}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>{t("retention.holds")}</CardTitle>
            </CardHeader>
            <CardContent className={"space-y-4"}>
              {route().has("clinicaldocumentation.retention.holds.store") && <HoldForm />}
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t("retention.patient_id")}</TableHead>
                    <TableHead>{t("retention.reason")}</TableHead>
                    <TableHead>{t("retention.placed_by")}</TableHead>
                    <TableHead />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {holds.map((hold) => (
                    <TableRow key={hold.id}>
                      <TableCell>{hold.patient_id}</TableCell>
                      <TableCell>{hold.reason}</TableCell>
                      <TableCell>{hold.placed_by_name}</TableCell>
                      <TableCell>{route().has("clinicaldocumentation.retention.holds.lift") && <LiftHoldButton hold={hold} />}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </div>
      </Content>
    </>
  );
};
Index.layout = (page: ReactNode) => <AuthenticatedLayout children={page} header={(t) => t("retention.title")} />;

export default Index;
