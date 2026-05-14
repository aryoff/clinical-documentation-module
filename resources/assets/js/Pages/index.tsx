import { ReactNode } from "react";
import { Head } from "@inertiajs/react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import { name } from "../../../../module.json";

const index = ({ message }: { message?: string }) => {
  return (
    <>
      <Head title={"Module: " + name} />

      <div className="py-12">
        <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
          <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-black">
            <div className="p-6 text-gray-900 dark:text-gray-100">{message}</div>
          </div>
        </div>
      </div>
    </>
  );
};

index.layout = (page: ReactNode) => <AuthenticatedLayout children={page} header={"Module: " + name} />;

export default index;
