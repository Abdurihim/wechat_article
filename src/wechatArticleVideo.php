<?php
/**
 * 获取文章内的视频和音频的真实地址
 * 资源地址可以直接下载
 */

namespace fawkes\wechat_article;


/**
 * Class ArticleVideo
 * @package fawkes\ArticleVideo
 */
class wechatArticleVideo
{
    /**
     * 抓取微信公众号的文章和里面的视频 url
     * @param $url
     * @return array|bool
     * @throws wechatArticleException
     */
    public function actionGetwx($url)
    {
        if (empty($url)) {
            throw new wechatArticleException("请输入公众号文章地址");
        }
        $info_id_arr = $this->getChatInfoId($url);
        //获取真实地址链接
        $info_arr = [];
        foreach ($info_id_arr as $key => $value) {
            //获取视频
            switch ($key) {
                case 'video':
                    $info_arr['video'] = [];
                    if (!empty($value)) {
                        foreach ($value as $vid) {
                            $video_json = Tools::getVqqInfo($vid);
                            if (!empty($video_json['msg']) && $video_json['msg'] == 'vid is wrong') {
                                //检测微视
                                $return = $this->weishiQQCom($vid);
                            } else {
                                //腾讯视频的真是地址获取
                                $return = $this->vQQCom($video_json);
                            }
                            $info_arr['video'][] = $return;
                        };
                    }
                    break;
                case 'voice':
                    $info_arr['voice'] = [];
                    if (!empty($value)) {
                        foreach ($value as $vid) {
                            $return = $this->voiceInfo($vid);
                            $info_arr['voice'][] = $return;
                        };
                    }
                    break;
                default:
                    break;
            }
        }
        return $info_arr;
    }

    /**
     * 获取公众号中的资源  音频和视频
     * @param $url
     * @return array
     * @throws wechatArticleException
     */
    private function getChatInfoId($url)
    {
        //微信的链接有长链和短链，以下为长链
        //$url ='http://mp.weixin.qq.com/s?__biz=MzI0NTc1MTczNA==&mid=2247485130&idx=1&sn=945cfb8b0cfdd99f1b730889de0216e2&chksm=e9488c13de3f05057be6c6b065f8e44d43c566cb9ee3a4f35cf8084382742159181ea480b935&scene=27';
        if (stripos($url, '?')) {
            if (stripos($url, '#wechat_redirect')) {
                $url = str_replace('#wechat_redirect', '', $url);
            }
            $json = $url . '&f=json';
        } else {
            $json = $url . '?f=json';
        }
        $data = Tools::curl_request($json);
        $data = json_decode($data, 1);
        $chat_info_id = [];
        //获取json中的得到视频vid
        $vid_arr = $data['video_ids'] ?? [];
        //获取json中的得到音频的mid
        $voice_arr = array_column($data['voice_in_appmsg'], 'voice_id') ?? [];
        if (empty($vid_arr)) {
            //data 为文章的详情
            $html = $data['content_noencode'];
            preg_match_all('/<iframe (.*?)data-src="(.*?)">/', $html, $matchs);
            //没有视频脚本退出
            if (empty($matchs[2])) {
                throw new wechatArticleException('没有视频匹配到，不采集');
            }
            //判断是否是url地址  而后解析得出 vid的值
            $url = current($matchs[2]);
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new wechatArticleException('视频地址异常：' . $url);
            }
            $url = str_replace('&amp;', '&', $url);
            //https://v.qq.com/iframe/preview.html?vid=i1324786hv8&width=500&height=375&auto=0
            $url_arr = parse_url($url);
            $query = $url_arr['query'] ?? '';
            $vidArray = explode("&vid=", $query);
            //获取到vid
            $vid_arr = [$vidArray[1]] ?? '';
            if (empty($vid_arr)) {
                throw new wechatArticleException('视频参数异常：' . $query);
            }
        }
        $chat_info_id['video'] = $vid_arr;
        $chat_info_id['voice'] = $voice_arr;
        return $chat_info_id;
    }

    /**
     * 腾讯微视获取真实地址
     * @param string $vid 视频资源地址
     * @return array
     */
    private function weishiQQCom($vid)
    {
        $url = 'https://mp.weixin.qq.com/mp/videoplayer?action=get_mp_video_play_url&vid=' . $vid;
        $data = Tools::curl_request($url);
        $data = json_decode($data, 1);
        //得到数据的json 组装成功url
        $format_id = $data['url_info'][0]['format_id'];
        $title = $data['title'];
        $url = $data['url_info'][0]['url'] . "&vid=$vid&format_id=$format_id";
        return [
            'vid' => $vid,
            'type' => '公众号素材视频',
            'title' => $title,
            'url' => $url
        ];
    }

    /**
     * 腾讯视频的处理url
     * @param array $video_json 腾讯视频数据
     * @return array
     */
    private function vQQCom(array $video_json)
    {
        $title = $video_json['vl']['vi'][0]['ti'];
        $vid = $video_json['vl']['vi'][0]['vid'];
        //高质量视频
        $fn_pre = $video_json['vl']['vi'][0]['lnk'];
        $host = $video_json['vl']['vi'][0]['ul']['ui'][0]['url'];
        $streams = $video_json['fl']['fi'];
        $seg_cnt = $video_json['vl']['vi'][0]['cl']['fc'];
        $best_quality = end($streams)['name'];
        $part_format_id = end($streams)['id'];
        $part_urls = [];
        for ($part = 1; $part <= $seg_cnt + 1; $part++) {
            $filename = $fn_pre . '.p' . ($part_format_id % 10000) . '.' . $part . '.mp4';
            $key_api = "http://vv.video.qq.com/getkey?otype=json&platform=11&format="
                . $part_format_id . "&vid=" . $vid . "&filename=" . $filename . "&appver=3.2.19.333";
            $part_info = Tools::curl($key_api);
            preg_match('/QZOutputJson=(.*);$/Uis', $part_info, $key_json);
            $key_json = json_decode($key_json[1], 1);
            if (empty($key_json['key'])) {
                $vkey = $video_json['vl']['vi'][0]['fvkey'];
                $url = $video_json['vl']['vi'][0]['ul']['ui'][0]['url'] . $fn_pre . '.mp4?vkey=' . $vkey;
            } else {
                $vkey = $key_json['key'];
                $url = $host . $filename . "?vkey=" . $vkey;
            }
            $part_urls[] = $url;
        }
        //真实的地址
        if (empty($part_urls)) {
            //获取的视频质量低
            if (!empty($video_json['vl']['vi'])) {
                $keys = [];
                foreach ($video_json['vl']['vi'] as $key => $value) {
                    $fvkey = $value['fvkey'];
                    $fn = $value['fn'];
                    $self_host = $value['ul']['ui'][$key]['url'];
                    $keys['fvkey'] = $fvkey;
                    $keys['fn'] = $fn;
                    $keys['self_host'] = $self_host;
                    $keys['lnk'] = $value['lnk'];
                }
                $part_urls[0] = $keys['self_host'] . $keys['fn'] . '?vkey=' . $keys['fvkey'];
            }
        }
        return [
            'vid' => $vid,
            'type' => '腾讯视频',
            'title' => $title,
            'url' => current($part_urls)
        ];
    }

    /**
     * 获取音频真实地址
     * @param string $vid
     * @return  array
     */
    private function voiceInfo(string $vid)
    {
        $url = 'https://res.wx.qq.com/voice/getvoice?mediaid=' . $vid;
        return [
            'vid' => $vid,
            'type' => '音频资料',
            'url' => $url
        ];
    }
}
