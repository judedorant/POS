<?php
namespace App\Reports;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\{Rule,ValidationException};
final class ReportPeriod {
 public static function from(Request $r):array {
  $tenant=$r->attributes->get('tenant');$today=CarbonImmutable::now($tenant->timezone);
  $data=$r->validate(['from'=>'sometimes|date_format:Y-m-d','to'=>'sometimes|date_format:Y-m-d','warehouse_id'=>['nullable','uuid',Rule::exists('warehouses','id')->where('tenant_id',$tenant->id)]]);
  $from=$data['from']??$today->subDays(29)->format('Y-m-d');$to=$data['to']??$today->format('Y-m-d');
  $start=CarbonImmutable::createFromFormat('!Y-m-d',$from,$tenant->timezone);$endDay=CarbonImmutable::createFromFormat('!Y-m-d',$to,$tenant->timezone);
  if($endDay->lessThan($start)||$start->diffInDays($endDay)>365)throw ValidationException::withMessages(['to'=>'Choose an inclusive reporting period of at most 366 days.']);
  return ['from'=>$from,'to'=>$to,'timezone'=>$tenant->timezone,'start_utc'=>$start->utc()->toISOString(),'end_utc'=>$endDay->addDay()->utc()->toISOString(),'warehouse_id'=>$data['warehouse_id']??null];
 }
}
