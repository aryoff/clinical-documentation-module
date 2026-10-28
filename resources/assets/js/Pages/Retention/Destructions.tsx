import { Head, router } from "@inertiajs/react";
import { type ReactNode } from "react";
import { Badge } from "@/Components/ui/badge";
import { Button } from "@/Components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/Components/ui/card";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";
import { useModuleT } from "@/hooks/useModuleT";
import { joinParts } from "../../Support/ids";

type Item = { id: string; custodian: string; ref_type: string; status: string; failure: string | null };
type DestructionRecord = { id: string; patient_id: string; reason: string; destroyed_by_name: string | null; started_at: string; status: string; items: Item[] };
type DestructionsProps = { destructions: DestructionRecord[]; destruction_enabled: boolean };

const Destructions = ({ destructions, destruction_enabled }: DestructionsProps) => {
  const { t } = useModuleT();
  return (
    <>
      <Head title={t("destruction.title")} />
      <Content>
        <div className={"space-y-6"}>
          <Card>
            <CardHeader>
              <CardTitle>{t("destruction.title")}</CardTitle>
              <CardDescription>{destruction_enabled ? t("retention.destruction_enabled") : t("retention.destruction_disabled")}</CardDescription>
            </CardHeader>
          </Card>
          {destructions.map((record) => (
            <Card key={record.id}>
              <CardHeader>
                <CardTitle>{t("destruction.patient_line", { patient: record.patient_id })}</CardTitle>
                <CardDescription>
                  <Badge id={joinParts("-", "status", record.id)}>{t(`destruction.status.${record.status}`)}</Badge> {record.reason}
                  {" · "}
                  {t("destruction.by_line", { name: record.destroyed_by_name ?? "", at: new Date(record.started_at).toLocaleString() })}
                </CardDescription>
              </CardHeader>
              <CardContent className={"space-y-2"}>
                <ul className={"list-disc pl-6"}>
                  {record.items.map((item) => (
                    <li key={item.id}>{t("destruction.item_line", { custodian: item.custodian, type: item.ref_type, status: t(`destruction.item_status.${item.status}`) })}</li>
                  ))}
                </ul>
                {destruction_enabled && record.status !== "complete" && route().has("clinicaldocumentation.retention.destruction.resume") && (
                  <Button id={joinParts("-", "resume", record.id)} variant={"outline"} onClick={() => router.post(route("clinicaldocumentation.retention.destruction.resume", record.id), {}, { preserveScroll: true })}>
                    {t("destruction.resume")}
                  </Button>
                )}
              </CardContent>
            </Card>
          ))}
        </div>
      </Content>
    </>
  );
};
Destructions.layout = (page: ReactNode) => <AuthenticatedLayout children={page} header={(t) => t("destruction.title")} />;

export default Destructions;
